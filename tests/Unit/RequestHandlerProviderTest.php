<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use FastRoute\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use SuperKernel\Attribute\Provider;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Middleware\MiddlewareCursor;
use SuperKernel\HttpServer\Provider\MiddlewareProvider;
use SuperKernel\HttpServer\Provider\RequestHandlerProvider;
use SuperKernel\HttpServer\Registry\MiddlewareRegistry;
use SuperKernel\HttpServer\Registry\RouteRegistry;
use SuperKernel\HttpServer\Response\ResponseConverter;
use SuperKernel\HttpServer\Routing\Dispatched;
use SuperKernel\HttpServer\Tests\Fixture\EmptyHttpDispatcherFactory;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use Throwable;

final class RequestHandlerProviderTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function invalidListenerAttributes(): iterable
    {
        yield 'missing' => [
            null,
        ];
        yield 'string' => [
            'http',
        ];
        yield 'object' => [
            (object) [
                'name' => 'http',
            ],
        ];
        yield 'missing name' => [
            [],
        ];
        yield 'empty name' => [
            [
                'name' => '',
            ],
        ];
        yield 'null name' => [
            [
                'name' => null,
            ],
        ];
        yield 'non-string name' => [
            [
                'name' => 1,
            ],
        ];
    }

    #[DataProvider('invalidListenerAttributes')]
    public function testRejectsAnInvalidListenerRequestAttribute(mixed $configuration): void
    {
        $dispatcher = $this->createMock(MiddlewareInterface::class);
        $dispatcher->expects(self::never())->method('process');
        $handler = new RequestHandlerProvider(
            new RouteRegistry(new TestAnnotationCollector()),
            new MiddlewareRegistry([], [], []),
            $dispatcher,
        );

        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageMatches('/super-kernel\.server\.listener/');

        $handler->handle(new ServerRequest('GET', '/')->withAttribute('super-kernel.server.listener', $configuration));
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        ResponseContext::clear();

        parent::tearDown();
    }

    /** @throws Throwable When the HTTP dispatcher fixture cannot be created */
    public function testImplementsPsrRequestHandlerAndDispatchesAnUnmatchedRequest(): void
    {
        $collector = new TestAnnotationCollector();
        $container = new TestContainer([]);
        $handler = new RequestHandlerProvider(
            new RouteRegistry($collector),
            new MiddlewareRegistry([], [], []),
            new MiddlewareProvider($container, new ResponseConverter(), EmptyHttpDispatcherFactory::create()),
        );
        ResponseContext::set(new Response());
        $request = new ServerRequest(method: 'GET', uri: '/missing')->withAttribute(
            'super-kernel.server.listener',
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
        );

        $reflection = new ReflectionClass(RequestHandlerProvider::class);
        $constructor = $reflection->getConstructor();
        $providers = $reflection->getAttributes(Provider::class);

        self::assertTrue($reflection->implementsInterface(RequestHandlerInterface::class));
        self::assertCount(1, $providers);
        self::assertSame(RequestHandlerInterface::class, $providers[0]->newInstance()->className);
        self::assertNotNull($constructor);
        self::assertCount(3, $constructor->getParameters());
        self::assertSame(RouteRegistry::class, (string) $constructor->getParameters()[0]->getType());
        self::assertSame(MiddlewareRegistry::class, (string) $constructor->getParameters()[1]->getType());
        self::assertSame(MiddlewareInterface::class, (string) $constructor->getParameters()[2]->getType());

        $response = $handler->handle($request);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', (string) $response->getBody());
        self::assertInstanceOf(Dispatched::class, RequestContext::get()->getAttribute(Dispatched::class));
    }

    /** @throws Throwable When the HTTP dispatcher fixture cannot be created */
    public function testOwnsAnImmutableMiddlewareCursorWithoutSharingItThroughDispatched(): void
    {
        $next = new class() implements MiddlewareInterface {
            public int $calls = 0;

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                ++$this->calls;

                return $handler->handle($request);
            }
        };
        $first = new class() implements MiddlewareInterface {
            public ?RequestHandlerInterface $handler = null;

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                $this->handler = $handler;
                $handler->handle($request);

                return $handler->handle($request);
            }
        };
        $handler = new RequestHandlerProvider(
            new RouteRegistry(new TestAnnotationCollector()),
            new MiddlewareRegistry([
                $first,
                $next,
            ], [], []),
            new MiddlewareProvider(
                new TestContainer([]),
                new ResponseConverter(),
                EmptyHttpDispatcherFactory::create(),
            ),
        );
        ResponseContext::set(new Response());
        $request = new ServerRequest(method: 'GET', uri: '/missing')->withAttribute(
            'super-kernel.server.listener',
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
        );

        $handler->handle($request);

        self::assertSame($handler, $first->handler);
        self::assertSame(2, $next->calls);
        self::assertNull(RequestContext::get()->getAttribute(MiddlewareCursor::class));
        self::assertInstanceOf(Dispatched::class, RequestContext::get()->getAttribute(Dispatched::class));
    }

    public function testDelegatesTheSelectedPlanWithoutConsumingMiddlewareItself(): void
    {
        $selected = new class() implements MiddlewareInterface {
            public int $calls = 0;

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                ++$this->calls;

                return new Response();
            }
        };
        $dispatcher = new class() implements MiddlewareInterface {
            public ?ServerRequestInterface $request = null;
            public ?RequestHandlerInterface $handler = null;

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                $this->request = $request;
                $this->handler = $handler;

                return new Response();
            }
        };
        $handler = new RequestHandlerProvider(
            new RouteRegistry(new TestAnnotationCollector()),
            new MiddlewareRegistry([
                $selected,
            ], [], []),
            $dispatcher,
        );
        $request = new ServerRequest(method: 'GET', uri: '/missing')->withAttribute(
            'super-kernel.server.listener',
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
        );

        $handler->handle($request);

        self::assertSame(0, $selected->calls);
        self::assertSame($handler, $dispatcher->handler);
        self::assertInstanceOf(MiddlewareCursor::class, $dispatcher->request?->getAttribute(
            MiddlewareCursor::class,
        ));
        self::assertSame($dispatcher->request, RequestContext::get());
    }

    /** @throws Throwable When the HTTP dispatcher fixture cannot be created */
    public function testSelectsTheMiddlewarePlanOnceBeforeTraversal(): void
    {
        $expected = new class() implements MiddlewareInterface {
            public int $calls = 0;

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                ++$this->calls;

                return $handler->handle($request);
            }
        };
        $unexpected = new class() implements MiddlewareInterface {
            public int $calls = 0;

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                ++$this->calls;

                return $handler->handle($request);
            }
        };
        $first = new class() implements MiddlewareInterface {
            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                return $handler->handle($request->withAttribute(
                    'super-kernel.server.listener',
                    [
                        'name' => 'admin',
                        'host' => '127.0.0.1',
                        'port' => 9502,
                    ],
                ));
            }
        };
        $handler = new RequestHandlerProvider(
            new RouteRegistry(new TestAnnotationCollector()),
            new MiddlewareRegistry([
                $first,
            ], [
                'http' => [
                    $expected,
                ],
                'admin' => [
                    $unexpected,
                ],
            ], []),
            new MiddlewareProvider(
                new TestContainer([]),
                new ResponseConverter(),
                EmptyHttpDispatcherFactory::create(),
            ),
        );
        ResponseContext::set(new Response());
        $request = new ServerRequest(method: 'GET', uri: '/missing')->withAttribute(
            'super-kernel.server.listener',
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
        );

        $handler->handle($request);

        self::assertSame(1, $expected->calls);
        self::assertSame(0, $unexpected->calls);
    }

    public function testEmptyPlanReachesTheDispatcherWithoutAddingAndRemovingACursor(): void
    {
        $dispatcher = new class() implements MiddlewareInterface {
            public ?ServerRequestInterface $request = null;

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                $this->request = $request;

                return new Response();
            }
        };
        $handler = new RequestHandlerProvider(
            new RouteRegistry(new TestAnnotationCollector()),
            new MiddlewareRegistry([], [], []),
            $dispatcher,
        );
        $request = new ServerRequest(method: 'GET', uri: '/missing')
            ->withAttribute(
                'super-kernel.server.listener',
                [
                    'name' => 'http',
                    'host' => '127.0.0.1',
                    'port' => 9501,
                ],
            )
            ->withAttribute(Dispatched::class, new Dispatched(Dispatcher::NOT_FOUND));

        $handler->handle($request);

        self::assertSame($request, $dispatcher->request);
        self::assertSame($request, RequestContext::get());
        self::assertNull($dispatcher->request?->getAttribute(MiddlewareCursor::class));
    }

    /** @throws Throwable When the HTTP dispatcher fixture cannot be created */
    public function testAResponseCanShortCircuitBeforeDispatchedFallback(): void
    {
        $response = new Response(statusCode: 202);
        $middleware = new class($response) implements MiddlewareInterface {
            public ?RequestHandlerInterface $handler = null;

            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                $this->handler = $handler;

                return $this->response;
            }
        };
        $handler = new RequestHandlerProvider(
            new RouteRegistry(new TestAnnotationCollector()),
            new MiddlewareRegistry([
                $middleware,
            ], [], []),
            new MiddlewareProvider(
                new TestContainer([]),
                new ResponseConverter(),
                EmptyHttpDispatcherFactory::create(),
            ),
        );
        $request = new ServerRequest(method: 'GET', uri: '/missing')->withAttribute(
            'super-kernel.server.listener',
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
        );

        self::assertSame($response, $handler->handle($request));
        self::assertSame($handler, $middleware->handler);
    }
}
