<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use FastRoute\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Constants\HttpMethod;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Registry\RouteRegistry;
use SuperKernel\HttpServer\Tests\Fixture\ExampleController;
use SuperKernel\HttpServer\Tests\Fixture\GreetingService;
use SuperKernel\HttpServer\Tests\Fixture\RouteValueController;
use SuperKernel\HttpServer\Tests\Fixture\SecondaryController;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\UnitRouteState;

use function sprintf;

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

    #[DataProvider('prefixedPaths')]
    public function testJoinsTheControllerPrefixAndTheRoutePath(
        string $prefix,
        string $path,
        string $found,
        string $notFound,
    ): void
    {
        $registry = new RouteRegistry(self::mappedCollector($prefix, [
            'string' => [
                $path,
            ],
        ]));
        $dispatched = $registry->dispatch('http', 'GET', $found);

        self::assertSame(Dispatcher::FOUND, $dispatched->status);
        self::assertSame('string', $dispatched->handler()->action);
        self::assertSame(Dispatcher::NOT_FOUND, $registry->dispatch('http', 'GET', $notFound)->status);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function prefixedPaths(): iterable
    {
        yield 'empty path at the prefix' => [
            '/user',
            '',
            '/user',
            '/user/',
        ];
        yield 'slash path after the prefix' => [
            '/user',
            '/',
            '/user/',
            '/user',
        ];
        yield 'empty path at a prefix with a trailing slash' => [
            '/user/',
            '',
            '/user',
            '/user/',
        ];
        yield 'slash path after a prefix with a trailing slash' => [
            '/user/',
            '/',
            '/user/',
            '/user',
        ];
        yield 'empty path at a prefix without a leading slash' => [
            'user',
            '',
            '/user',
            '/user/',
        ];
        yield 'named path after a prefix with a trailing slash' => [
            '/user/',
            '/list',
            '/user/list',
            '/user/list/',
        ];
        yield 'named path without a leading slash' => [
            '/user',
            'list',
            '/user/list',
            '/user',
        ];
        yield 'empty path without a prefix' => [
            '',
            '',
            '/',
            '/user',
        ];
        yield 'slash path without a prefix' => [
            '',
            '/',
            '/',
            '/user',
        ];
        yield 'empty path at the root prefix' => [
            '/',
            '',
            '/',
            '/user',
        ];
    }

    public function testKeepsTheEmptyAndSlashPathsOfOnePrefixApart(): void
    {
        $registry = new RouteRegistry(self::mappedCollector('/user', [
            'string' => [
                '',
            ],
            'mixed' => [
                '/',
            ],
            'int' => [
                '/{value:\\d+}',
            ],
        ]));

        self::assertSame('string', $registry->dispatch('http', 'GET', '/user')->handler()->action);
        self::assertSame('mixed', $registry->dispatch('http', 'GET', '/user/')->handler()->action);
        self::assertSame('int', $registry->dispatch('http', 'GET', '/user/42')->handler()->action);
    }

    public function testRejectsTheEmptyAndSlashPathsTogetherWithoutAPrefix(): void
    {
        $collector = self::mappedCollector('', [
            'string' => [
                '',
            ],
            'mixed' => [
                '/',
            ],
        ]);

        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('Unable to build the HTTP route table.');

        new RouteRegistry($collector);
    }

    #[DataProvider('routeVariableParameters')]
    public function testAcceptsRouteVariablesForConvertibleParameterTypes(string $action): void
    {
        $registry = new RouteRegistry(self::mappedCollector('/values', [
            $action => [
                '/{value}',
            ],
        ]));

        self::assertSame($action, $registry->dispatch('http', 'GET', '/values/1')->handler()->action);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routeVariableParameters(): iterable
    {
        foreach ([
            'string',
            'mixed',
            'int',
            'nullableInt',
            'float',
            'bool',
            'stringEnum',
            'intEnum',
            'union',
        ] as $action) {
            yield $action => [
                $action,
            ];
        }
    }

    #[DataProvider('unsupportedRouteVariableParameters')]
    public function testRejectsARouteVariableWhoseParameterTypeCannotReceiveIt(
        string $path,
        string $action,
        string $type,
    ): void
    {
        $collector = self::mappedCollector('/values', [
            $action => [
                $path,
            ],
        ]);

        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessage(sprintf(
            'Route parameter $value of %s::%s() has type %s, which cannot receive a route value.',
            RouteValueController::class,
            $action,
            $type,
        ));

        new RouteRegistry($collector);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unsupportedRouteVariableParameters(): iterable
    {
        yield 'class' => [
            '/{value}',
            'object',
            GreetingService::class,
        ];
        yield 'unit enum' => [
            '/{value:[a-z]+}',
            'unitEnum',
            UnitRouteState::class,
        ];
        yield 'array in an optional segment' => [
            '/list[/{value}]',
            'array',
            'array',
        ];
    }

    public function testIgnoresAParameterTypeThatNoRouteVariableNames(): void
    {
        $registry = new RouteRegistry(self::mappedCollector('/values', [
            'object' => [
                '/{other}',
            ],
        ]));

        self::assertSame('object', $registry->dispatch('http', 'GET', '/values/1')->handler()->action);
    }

    /**
     * @param array<string, list<string>> $paths route paths by RouteValueController action
     */
    private static function mappedCollector(string $prefix, array $paths): TestAnnotationCollector
    {
        $controller = new AttributeMetadata(new Controller(
            prefix : $prefix,
        ));
        $methodAttributes = [];

        foreach ($paths as $action => $actionPaths) {
            foreach ($actionPaths as $path) {
                $methodAttributes[RouteValueController::class . '::' . $action][] = new AttributeMetadata(
                    new RequestMapping(
                        path   : $path,
                        method : HttpMethod::Get,
                    ),
                );
            }
        }

        return new TestAnnotationCollector(
            classesByAttribute: [
                Controller::class => [
                    RouteValueController::class => [
                        $controller,
                    ],
                ],
            ],
            methodAttributes: $methodAttributes,
        );
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
