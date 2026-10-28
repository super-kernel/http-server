<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Registry;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use ReflectionClass;
use ReflectionMethod;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AttributeMetadataInterface;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Routing\Dispatched;
use SuperKernel\HttpServer\Routing\RouteDefinition;
use Throwable;

use function FastRoute\simpleDispatcher;
use function get_debug_type;
use function ltrim;
use function rtrim;
use function sprintf;

final readonly class RouteRegistry
{
    /** @var array<non-empty-string, Dispatcher> */
    private array $dispatchers;

    public function __construct(AnnotationCollectorInterface $collector)
    {
        /**
         * @var array<non-empty-string, list<array{
         *     prefix: string,
         *     routes: list<array{
         *         method: non-empty-string,
         *         path: non-empty-string,
         *         handler: RouteDefinition
         *     }>
         * }>> $routeGroups
         */
        $routeGroups = [];

        foreach ($collector->getClassesWithAttribute(Controller::class) as $className => [$metadata]) {
            $controller = $metadata->attribute();
            $reflection = self::controllerReflection($className);
            $routes = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $className !== $method->getDeclaringClass()->getName()) {
                    continue;
                }

                $methodAttributes = $collector->getMethodAttributes($className, $method->getName());
                $mappings = self::requestMappings($methodAttributes);

                foreach ($mappings as $mapping) {
                    $routes[] = [
                        'method'  => $mapping->method,
                        'path'    => self::routePath($mapping->path),
                        'handler' => new RouteDefinition(
                            $className,
                            $method->getName(),
                        ),
                    ];
                }
            }

            if ([] !== $routes) {
                $routeGroups[$controller->server][] = [
                    'prefix' => self::groupPrefix($controller->prefix),
                    'routes' => $routes,
                ];
            }
        }

        $dispatchers = [];

        try {
            foreach ($routeGroups as $serverName => $serverRouteGroups) {
                $dispatchers[$serverName] = simpleDispatcher(
                    static function (RouteCollector $collector) use ($serverRouteGroups): void {
                        foreach ($serverRouteGroups as $routeGroup) {
                            $collector->addGroup(
                                $routeGroup['prefix'],
                                static function (RouteCollector $collector) use ($routeGroup): void {
                                    foreach ($routeGroup['routes'] as $route) {
                                        $collector->addRoute($route['method'], $route['path'], $route['handler']);
                                    }
                                },
                            );
                        }
                    },
                );
            }
        } catch (Throwable $throwable) {
            throw new InvalidHttpDeclarationException('Unable to build the HTTP route table.', 0, $throwable);
        }

        $this->dispatchers = $dispatchers;
    }

    /**
     * @param non-empty-string $serverName
     */
    public function dispatch(string $serverName, string $method, string $path): Dispatched
    {
        $dispatcher = $this->dispatchers[$serverName] ?? null;

        if (null === $dispatcher) {
            return new Dispatched(Dispatcher::NOT_FOUND);
        }

        $result = $dispatcher->dispatch($method, $path);
        $status = $result[0];

        if (Dispatcher::NOT_FOUND === $status) {
            return new Dispatched($status);
        }

        if (Dispatcher::METHOD_NOT_ALLOWED === $status) {
            return new Dispatched(
                $status,
                allowedMethods: $result[1],
            );
        }

        if (Dispatcher::FOUND !== $status) {
            throw new InvalidHttpDeclarationException('The HTTP route dispatcher returned an unsupported status.');
        }

        $route = $result[1];

        if (!$route instanceof RouteDefinition) {
            throw new InvalidHttpDeclarationException(sprintf(
                'HTTP route handler must be %s; %s provided.',
                RouteDefinition::class,
                get_debug_type($route),
            ));
        }

        return new Dispatched(
            $status,
            $route,
            $result[2],
        );
    }

    /**
     * @param list<AttributeMetadataInterface<object>> $attributes
     *
     * @return list<RequestMapping>
     */
    private static function requestMappings(array $attributes): array
    {
        $instances = [];

        foreach ($attributes as $attribute) {
            if (RequestMapping::class === $attribute->name()) {
                /** @var RequestMapping $instance */
                $instance = $attribute->attribute();
                $instances[] = $instance;
            }
        }

        return $instances;
    }

    /**
     * @param class-string $className
     *
     * @return ReflectionClass<object>
     */
    private static function controllerReflection(string $className): ReflectionClass
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (Throwable $throwable) {
            throw new InvalidHttpDeclarationException(sprintf(
                'Unable to reflect HTTP controller %s.',
                $className,
            ), 0, $throwable);
        }

        if (!$reflection->isInstantiable()) {
            throw new InvalidHttpDeclarationException(sprintf(
                'HTTP controller %s must be instantiable.',
                $className,
            ));
        }

        return $reflection;
    }

    private static function groupPrefix(string $prefix): string
    {
        return rtrim('/' . ltrim($prefix, '/'), '/');
    }

    /** @return non-empty-string */
    private static function routePath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }
}
