<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Provider\MiddlewareRegistryProvider;
use SuperKernel\HttpServer\Registry\MiddlewareRegistry;
use SuperKernel\HttpServer\Routing\RouteDefinition;
use SuperKernel\HttpServer\Tests\Fixture\ExampleController;
use SuperKernel\HttpServer\Tests\Fixture\GlobalRecordingMiddleware;
use SuperKernel\HttpServer\Tests\Fixture\InvocationLog;
use SuperKernel\HttpServer\Tests\Fixture\RouteRecordingMiddleware;
use SuperKernel\HttpServer\Tests\Fixture\ServerRecordingMiddleware;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use Throwable;

use function array_map;

final class MiddlewareRegistryProviderTest extends TestCase
{
    public function testDeclaresTheMiddlewareRegistryProvider(): void
    {
        $reflection = new ReflectionClass(MiddlewareRegistryProvider::class);
        $attributes = $reflection->getAttributes(FactoryProvider::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertCount(1, $attributes);
        self::assertSame(MiddlewareRegistry::class, $attributes[0]->newInstance()->className);
    }

    public function testUsesServerTerminologyForNamedMiddleware(): void
    {
        $reflection = new ReflectionClass(MiddlewareRegistry::class);

        self::assertTrue($reflection->hasMethod('serverMiddlewares'));
        self::assertFalse($reflection->hasMethod('serviceMiddlewares'));
        self::assertSame([
            'globalMiddlewares',
            'serverMiddlewares',
            'routeMiddlewares',
        ], array_map(
            static fn($parameter): string => $parameter->getName(),
            $reflection->getConstructor()?->getParameters() ?? [],
        ));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCompilesGlobalServerAndRouteMiddlewarePlansInStableLayerOrder(): void
    {
        $log = new InvocationLog();
        $global = new GlobalRecordingMiddleware($log);
        $server = new ServerRecordingMiddleware($log);
        $route = new RouteRecordingMiddleware($log);
        $controller = new AttributeMetadata(new Controller());
        $collector = new TestAnnotationCollector(
            classesByAttribute: [
                Controller::class => [
                    ExampleController::class => [
                        $controller,
                    ],
                ],
                Middleware::class => [
                    GlobalRecordingMiddleware::class => [
                        new AttributeMetadata(new Middleware()),
                    ],
                    ServerRecordingMiddleware::class => [
                        new AttributeMetadata(new Middleware(
                            name : 'http',
                        )),
                    ],
                ],
            ],
            classAttributes: [
                ExampleController::class => [
                    $controller,
                    new AttributeMetadata(new Middlewares(
                        middlewares : [
                            RouteRecordingMiddleware::class,
                        ],
                    )),
                ],
            ],
        );
        $registry = new MiddlewareRegistryProvider()($collector, new TestContainer([
            GlobalRecordingMiddleware::class => $global,
            ServerRecordingMiddleware::class => $server,
            RouteRecordingMiddleware::class  => $route,
        ]));
        $definition = new RouteDefinition(ExampleController::class, 'show');

        self::assertSame([
            $global,
        ], $registry->globalMiddlewares());
        self::assertSame([
            $server,
        ], $registry->serverMiddlewares('http'));
        self::assertSame([], $registry->serverMiddlewares('admin'));
        self::assertSame([
            $route,
        ], $registry->routeMiddlewares($definition));
        self::assertSame([
            $global,
            $server,
            $route,
        ], $registry->middlewares('http', $definition));
        self::assertSame([
            $global,
            $route,
        ], $registry->middlewares('admin', $definition));
        self::assertSame([
            $global,
            $server,
        ], $registry->middlewares('http'));
        self::assertSame([
            $global,
        ], $registry->middlewares('admin'));
    }

    public function testCompiledPlansShareMiddlewareObjectsWithoutOwningConsumptionState(): void
    {
        $log = new InvocationLog();
        $firstMiddleware = new GlobalRecordingMiddleware($log);
        $secondMiddleware = new ServerRecordingMiddleware($log);
        $registry = new MiddlewareRegistry([
            $firstMiddleware,
            $secondMiddleware,
        ], [], []);
        $first = $registry->middlewares('http');
        $second = $registry->middlewares('http');

        self::assertSame([
            $firstMiddleware,
            $secondMiddleware,
        ], $first);
        self::assertSame($first, $second);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAnnotatedClassThatIsNotPsrMiddleware(): void
    {
        $middleware = new AttributeMetadata(new Middleware(
            name : 'http',
        ));
        $collector = new TestAnnotationCollector(classesByAttribute: [
            Middleware::class => [
                ExampleController::class => [
                    $middleware,
                ],
            ],
        ]);

        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('must implement Psr\\Http\\Server\\MiddlewareInterface');

        new MiddlewareRegistryProvider()($collector, new TestContainer([]));
    }

}
