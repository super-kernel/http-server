<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use FastRoute\Dispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Constants\HttpMethod;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Registry\RouteRegistry;
use SuperKernel\HttpServer\Tests\Fixture\ExampleController;
use SuperKernel\HttpServer\Tests\Fixture\SecondaryController;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;

final class RouteRegistryTest extends TestCase
{
    public function testDispatchesControllerPrefixedRouteDefinition(): void
    {
        $collector = self::collector();
        $registry = new RouteRegistry($collector);
        $dispatched = $registry->dispatch('http', 'GET', '/api/widgets/42');

        self::assertSame(Dispatcher::FOUND, $dispatched->status);
        self::assertSame(ExampleController::class, $dispatched->handler()->controller);
        self::assertSame('show', $dispatched->handler()->action);
        self::assertSame([
            'id' => '42',
        ], $dispatched->variables);
        self::assertSame(
            Dispatcher::NOT_FOUND,
            $registry->dispatch('admin', 'GET', '/api/widgets/42')->status,
        );
    }

    public function testAllowsTheSameRouteOnDifferentNamedServers(): void
    {
        $httpController = new AttributeMetadata(new Controller(
            prefix : '/api',
            server : 'http',
        ));
        $adminController = new AttributeMetadata(new Controller(
            prefix : '/api',
            server : 'admin',
        ));
        $mapping = new AttributeMetadata(new RequestMapping(
            path   : '/widgets/{id:\\d+}',
            method : HttpMethod::Get,
        ));
        $collector = new TestAnnotationCollector(
            classesByAttribute: [
                Controller::class => [
                    ExampleController::class => [
                        $httpController,
                    ],
                    SecondaryController::class => [
                        $adminController,
                    ],
                ],
            ],
            methodAttributes: [
                ExampleController::class . '::show' => [
                    $mapping,
                ],
                SecondaryController::class . '::show' => [
                    $mapping,
                ],
            ],
        );
        $registry = new RouteRegistry($collector);

        self::assertSame(
            ExampleController::class,
            $registry->dispatch('http', 'GET', '/api/widgets/42')->handler()->controller,
        );
        self::assertSame(
            SecondaryController::class,
            $registry->dispatch('admin', 'GET', '/api/widgets/42')->handler()->controller,
        );
    }

    public function testRejectsDuplicateRoutes(): void
    {
        $collector = self::collector(duplicateMapping: true);

        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('Unable to build the HTTP route table.');

        new RouteRegistry($collector);
    }

    public function testRejectsControllerThatCannotBeInstantiated(): void
    {
        $collector = new TestAnnotationCollector(classesByAttribute: [
            Controller::class => [
                ServerRequestInterface::class => [
                    new AttributeMetadata(new Controller()),
                ],
            ],
        ]);

        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('must be instantiable');

        new RouteRegistry($collector);
    }

    private static function collector(bool $duplicateMapping = false): TestAnnotationCollector
    {
        $controller = new AttributeMetadata(new Controller(
            prefix : '/api',
        ));
        $mapping = new AttributeMetadata(new RequestMapping(
            path   : '/widgets/{id:\\d+}',
            method : HttpMethod::Get,
        ));

        return new TestAnnotationCollector(
            classesByAttribute: [
                Controller::class => [
                    ExampleController::class => [
                        $controller,
                    ],
                ],
            ],
            classAttributes: [
                ExampleController::class => [
                    $controller,
                ],
            ],
            methodAttributes: [
                ExampleController::class . '::show' => $duplicateMapping
                    ? [
                        $mapping,
                        $mapping,
                    ]
                    : [
                        $mapping,
                    ],
            ],
        );
    }
}
