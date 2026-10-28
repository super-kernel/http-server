<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use FastRoute\Dispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use stdClass;
use SuperKernel\Attribute\Provider;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use SuperKernel\HttpServer\Middleware\MiddlewareCursor;
use SuperKernel\HttpServer\Provider\MiddlewareProvider;
use SuperKernel\HttpServer\Response\ResponseConverter;
use SuperKernel\HttpServer\Routing\Dispatched;
use SuperKernel\HttpServer\Routing\RouteDefinition;
use SuperKernel\HttpServer\Routing\RouteDispatcher;
use SuperKernel\HttpServer\Tests\Fixture\EmptyHttpDispatcherFactory;
use SuperKernel\HttpServer\Tests\Fixture\InvocationLog;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use Throwable;
use TypeError;

use function array_map;

final class MiddlewareProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
        ResponseContext::clear();

        parent::tearDown();
    }

    public function testDeclaresThePsrMiddlewareProviderWithTheResponseConverter(): void
    {
        $reflection = new ReflectionClass(MiddlewareProvider::class);
        $attributes = $reflection->getAttributes(Provider::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->implementsInterface(MiddlewareInterface::class));
        self::assertSame('mixed', (string) $reflection->getMethod('handleFound')->getReturnType());
        self::assertCount(1, $attributes);
        self::assertSame(MiddlewareInterface::class, $attributes[0]->newInstance()->className);
        self::assertSame([
            ContainerInterface::class,
            ResponseConverter::class,
            HttpExceptionDispatcherFactory::class,
        ], array_map(
            static fn($parameter): string => (string)$parameter->getType(),
            $reflection->getConstructor()?->getParameters() ?? [],
        ));
    }

    /** @throws Throwable When the PSR container returns an incompatible route dispatcher */
    public function testWrongPsrDispatcherFailsBeforeDispatch(): void
    {
        $container = new TestContainer([
            RouteDispatcher::class => new stdClass(),
        ]);
        $middleware = new MiddlewareProvider($container, new ResponseConverter(), EmptyHttpDispatcherFactory::create());
        $request = $this->request(new Dispatched(
            Dispatcher::FOUND,
            new RouteDefinition(stdClass::class, 'handle'),
        ));

        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains('MiddlewareProvider::dispatcher()');

        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testHandlesNotFoundWithoutResolvingTheFoundRouteTerminal(): void
    {
        $response = $this->middleware()->process(
            $this->request(new Dispatched(Dispatcher::NOT_FOUND)),
            $this->createStub(RequestHandlerInterface::class),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', (string)$response->getBody());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testHandlesMethodNotAllowedWithoutResolvingTheFoundRouteTerminal(): void
    {
        $response = $this->middleware()->process(
            $this->request(new Dispatched(
                Dispatcher::METHOD_NOT_ALLOWED,
                allowedMethods: [
                    'GET',
                    'HEAD',
                ],
            )),
            $this->createStub(RequestHandlerInterface::class),
        );

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        self::assertSame('Method Not Allowed', (string)$response->getBody());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsARequestWithoutDispatchedState(): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains(Dispatched::class);

        $this->middleware()->process(
            new ServerRequest('GET', ''),
            $this->createStub(RequestHandlerInterface::class),
        );
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testConsumesRemainingMiddlewareThroughTheProvidedHandlerBeforeFallback(): void
    {
        $log = new InvocationLog();
        $first = new class($log) implements MiddlewareInterface {
            public ?RequestHandlerInterface $handler = null;

            public function __construct(private readonly InvocationLog $log)
            {
            }

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                $this->handler = $handler;
                $this->log->entries[] = 'first:before';
                $response = $handler->handle($request);
                $this->log->entries[] = 'first:after';

                return $response;
            }
        };
        $second = new readonly class($log) implements MiddlewareInterface {
            public function __construct(private InvocationLog $log)
            {
            }

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                $this->log->entries[] = 'second:before';
                $response = $handler->handle($request);
                $this->log->entries[] = 'second:after';

                return $response;
            }
        };
        $middleware = $this->middleware();
        $handler = new class($middleware) implements RequestHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly MiddlewareProvider $middleware)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return $this->middleware->process($request, $this);
            }
        };
        $request = $this->request(new Dispatched(Dispatcher::NOT_FOUND))->withAttribute(
            MiddlewareCursor::class,
            new MiddlewareCursor([
                $first,
                $second,
            ]),
        );

        $response = $middleware->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(2, $handler->calls);
        self::assertSame($handler, $first->handler);
        self::assertSame([
            'first:before',
            'second:before',
            'second:after',
            'first:after',
        ], $log->entries);
        self::assertNull(RequestContext::get()->getAttribute(MiddlewareCursor::class));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testMiddlewareCanShortCircuitBeforeDispatchedFallback(): void
    {
        $response = new Response(statusCode: 202);
        $middleware = new readonly class($response) implements MiddlewareInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function process(
                ServerRequestInterface  $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface
            {
                return $this->response;
            }
        };
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $request = new ServerRequest('GET', '')->withAttribute(
            MiddlewareCursor::class,
            new MiddlewareCursor([
                $middleware,
            ]),
        );

        self::assertSame($response, $this->middleware()->process($request, $handler));
    }

    /** @throws Throwable When fixture setup or inspection fails */
    private function middleware(): MiddlewareProvider
    {
        return new MiddlewareProvider(
            new TestContainer([]),
            new ResponseConverter(),
            EmptyHttpDispatcherFactory::create(),
        );
    }

    private function request(Dispatched $dispatched): ServerRequest
    {
        ResponseContext::set(new Response());

        return new ServerRequest('GET', '')->withAttribute(Dispatched::class, $dispatched)
            ->withAttribute('super-kernel.server.listener', [
                'name' => 'http',
            ]);
    }
}
