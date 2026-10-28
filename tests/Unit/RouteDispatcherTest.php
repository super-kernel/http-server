<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use ArrayObject;
use Error;
use FastRoute\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Routing\ControllerArgumentResolver;
use SuperKernel\HttpServer\Routing\Dispatched;
use SuperKernel\HttpServer\Routing\RouteDefinition;
use SuperKernel\HttpServer\Routing\RouteDispatcher;
use SuperKernel\HttpServer\Tests\Fixture\ExampleController;
use SuperKernel\HttpServer\Tests\Fixture\InvocationLog;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Throwable;
use TypeError;

use function get_debug_type;
use function sprintf;

final class RouteDispatcherTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function nonObjectControllers(): iterable
    {
        yield 'integer' => [
            42,
            'int',
        ];
        yield 'string' => [
            'controller',
            'string',
        ];
        yield 'boolean' => [
            false,
            'bool',
        ];
        yield 'float' => [
            1.5,
            'float',
        ];
        yield 'array' => [
            [],
            'array',
        ];
        yield 'null' => [
            null,
            'null',
        ];
    }

    /** @return iterable<string, array{Throwable}> */
    public static function containerFailures(): iterable
    {
        yield 'missing entry' => [
            new class('controller missing') extends RuntimeException implements NotFoundExceptionInterface {},
        ];
        yield 'resolution failure' => [
            new class(
                'controller resolution failed',
            ) extends RuntimeException implements ContainerExceptionInterface {},
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function nonPublicActions(): iterable
    {
        yield 'private' => [
            'privateAction',
        ];
        yield 'protected' => [
            'protectedAction',
        ];
    }

    protected function tearDown(): void
    {
        RequestContext::clear();

        parent::tearDown();
    }

    public function testIsTheFoundRouteResultDispatcher(): void
    {
        $reflection = new ReflectionClass(RouteDispatcher::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertFalse($reflection->implementsInterface(RequestHandlerInterface::class));
        self::assertSame('mixed', (string) $reflection->getMethod('handle')->getReturnType());
        self::assertNotNull($constructor);
        self::assertCount(2, $constructor->getParameters());
        self::assertSame(ContainerInterface::class, (string) $constructor->getParameters()[0]->getType());
        self::assertSame(ControllerArgumentResolver::class, (string) $constructor->getParameters()[1]->getType());
    }

    /**
     * @throws ReflectionException
     * @throws Throwable           When fixture setup or the operation under test throws
     */
    public function testReturnsTheRawControllerResultFromAFoundRoute(): void
    {
        $controller = new class() {
            /**
             * @return array{id: int, routeAttribute: string}
             */
            public function show(ServerRequestInterface $request, int $id): array
            {
                return [
                    'id'             => $id,
                    'routeAttribute' => $request->getAttribute('id'),
                ];
            }
        };
        $route = new RouteDefinition($controller::class, 'show');
        $method = new ReflectionMethod($controller::class, 'show');
        $reflector = $this->createMock(ReflectorInterface::class);
        $reflector->expects(self::once())
            ->method('reflectMethod')
            ->with($controller::class, 'show')
            ->willReturn($method);
        $request = $this->request(new Dispatched(
            Dispatcher::FOUND,
            $route,
            [
                'id' => '42',
            ],
        ));

        $result = $this->dispatcher([
            $controller::class => $controller,
        ], $reflector)->handle($request);

        self::assertSame([
            'id'             => 42,
            'routeAttribute' => '42',
        ], $result);
        self::assertSame($route, RequestContext::get()->getAttribute(RouteDefinition::class));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testReturnsNullForAVoidControllerResult(): void
    {
        $controller = new class() {
            public function handle(): void
            {
            }
        };
        $route = new RouteDefinition($controller::class, 'handle');

        self::assertNull($this->dispatcher([
            $controller::class => $controller,
        ])->handle($this->request(new Dispatched(Dispatcher::FOUND, $route))));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsANonFoundDispatch(): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('requires a found');

        $this->dispatcher()->handle($this->request(new Dispatched(Dispatcher::NOT_FOUND)));
    }

    /** @throws Throwable When a PSR container returns a non-object controller */
    #[DataProvider('nonObjectControllers')]
    public function testNonObjectControllerFailsBeforeResolvingActionArguments(mixed $entry, string $type): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(ExampleController::class)->willReturn($entry);
        $reflector = $this->createMock(ReflectorInterface::class);
        $reflector->expects(self::never())->method('reflectMethod');
        $dispatcher = new RouteDispatcher($container, new ControllerArgumentResolver($container, $reflector));

        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIs(sprintf(
            'HTTP controller %s must resolve to a compatible instance, %s returned.',
            ExampleController::class,
            $type,
        ));

        $dispatcher->handle($this->request(new Dispatched(
            Dispatcher::FOUND,
            new RouteDefinition(ExampleController::class, 'show'),
        )));
    }

    /** @throws Throwable When a PSR container returns an unrelated controller with a matching action */
    public function testUnrelatedControllerNeverResolvesArgumentsOrExecutesItsAction(): void
    {
        $log = new InvocationLog();
        $controller = new readonly class($log) {
            public function __construct(private InvocationLog $log)
            {
            }

            public function show(): string
            {
                $this->log->entries[] = 'unexpected action';

                return 'unrelated controller';
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(ExampleController::class)->willReturn($controller);
        $reflector = $this->createMock(ReflectorInterface::class);
        $reflector->expects(self::never())->method('reflectMethod');
        $dispatcher = new RouteDispatcher($container, new ControllerArgumentResolver($container, $reflector));

        try {
            $dispatcher->handle($this->request(new Dispatched(
                Dispatcher::FOUND,
                new RouteDefinition(ExampleController::class, 'show'),
            )));
            self::fail('An unrelated controller must not execute.');
        } catch (InvalidHttpDeclarationException $exception) {
            self::assertSame(sprintf(
                'HTTP controller %s must resolve to a compatible instance, %s returned.',
                ExampleController::class,
                get_debug_type($controller),
            ), $exception->getMessage());
        }

        self::assertSame([], $log->entries);
    }

    /** @throws Throwable When fixture setup or the compatible subclass action fails */
    public function testAcceptsASubclassOfTheDeclaredController(): void
    {
        $controller = new class() extends ArrayObject {
            public function count(): int
            {
                return 7;
            }
        };

        self::assertSame(7, $this->dispatcher([
            ArrayObject::class => $controller,
        ])->handle($this->request(new Dispatched(
            Dispatcher::FOUND,
            new RouteDefinition(ArrayObject::class, 'count'),
        ))));
    }

    /** @throws Throwable When fixture setup or the compatible proxy action fails */
    public function testAcceptsAProxyImplementingTheDeclaredControllerInterface(): void
    {
        $response = new Response(statusCode: 202);
        $delegate = $this->createMock(RequestHandlerInterface::class);
        $delegate->expects(self::once())->method('handle')->willReturn($response);
        $controller = new readonly class($delegate) implements RequestHandlerInterface {
            public function __construct(private RequestHandlerInterface $delegate)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->delegate->handle($request);
            }
        };

        self::assertSame($response, $this->dispatcher([
            RequestHandlerInterface::class => $controller,
        ])->handle($this->request(new Dispatched(
            Dispatcher::FOUND,
            new RouteDefinition(RequestHandlerInterface::class, 'handle'),
        ))));
    }

    /** @throws Throwable When the original container failure propagates */
    #[DataProvider('containerFailures')]
    public function testPreservesTheOriginalContainerFailure(Throwable $failure): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(ExampleController::class)->willThrowException($failure);
        $reflector = $this->createMock(ReflectorInterface::class);
        $reflector->expects(self::never())->method('reflectMethod');
        $dispatcher = new RouteDispatcher($container, new ControllerArgumentResolver($container, $reflector));

        $this->expectExceptionObject($failure);

        try {
            $dispatcher->handle($this->request(new Dispatched(
                Dispatcher::FOUND,
                new RouteDefinition(ExampleController::class, 'show'),
            )));
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);

            throw $caught;
        }
    }

    /** @throws Throwable When a manually supplied route names a non-public action */
    #[DataProvider('nonPublicActions')]
    public function testNonPublicActionsKeepTheirNativeError(string $action): void
    {
        $controller = new class() {
            /**
             * Reflected by the route resolver to exercise native visibility enforcement.
             *
             * @noinspection PhpUnusedPrivateMethodInspection
             */
            private function privateAction(): void
            {
            }

            protected function protectedAction(): void
            {
            }
        };
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches(sprintf(
            '/^Call to (private|protected) method .*::%s\\(\\) from scope /',
            $action,
        ));

        $this->dispatcher([
            $controller::class => $controller,
        ])->handle($this->request(new Dispatched(
            Dispatcher::FOUND,
            new RouteDefinition($controller::class, $action),
        )));
    }

    /** @throws Throwable When a manually supplied route names a missing action */
    public function testMissingActionKeepsTheOriginalDeclarationError(): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('Unable to reflect HTTP controller action');

        $this->dispatcher([
            stdClass::class => new stdClass(),
        ])->handle($this->request(new Dispatched(
            Dispatcher::FOUND,
            new RouteDefinition(stdClass::class, 'missingAction'),
        )));
    }

    /** @throws Throwable When an action dependency has an incompatible native parameter type */
    public function testWrongPsrActionDependencyFailsAtTheActionParameter(): void
    {
        $controller = new class() {
            public function handle(RequestHandlerInterface $dependency): RequestHandlerInterface
            {
                return $dependency;
            }
        };
        $route = new RouteDefinition($controller::class, 'handle');

        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains(RequestHandlerInterface::class);

        $this->dispatcher([
            $controller::class             => $controller,
            RequestHandlerInterface::class => new stdClass(),
        ])->handle($this->request(new Dispatched(Dispatcher::FOUND, $route)));
    }

    /**
     * @param array<string, object> $entries
     */
    private function dispatcher(
        array               $entries = [],
        ?ReflectorInterface $reflector = null,
    ): RouteDispatcher
    {
        $container = new TestContainer($entries);

        return new RouteDispatcher(
            $container,
            new ControllerArgumentResolver($container, $reflector ?? new ReflectorProvider()()),
        );
    }

    private function request(Dispatched $dispatched): ServerRequestInterface
    {
        return new ServerRequest('GET', '')->withAttribute(Dispatched::class, $dispatched);
    }
}
