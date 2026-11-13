<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Registry;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use ReflectionClass;
use ReflectionMethod;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Routing\Dispatched;
use SuperKernel\HttpServer\Routing\RouteDefinition;
use SuperKernel\HttpServer\Routing\RouteValueConverter;
use Throwable;

use function FastRoute\simpleDispatcher;
use function get_debug_type;
use function is_array;
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
         *     method: non-empty-string,
         *     path: non-empty-string,
         *     handler: RouteDefinition
         * }>> $serverRoutes
         */
        $serverRoutes = [];
        $routeParser = new Std();

        foreach ($collector->getClassesWithAttribute(Controller::class) as $className => [$metadata]) {
            $controller = $metadata->attribute();

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

            // The prefix starts with a slash and has none at its end, so that an empty route path answers at the
            // prefix itself and a route path "/" at the prefix followed by a slash.
            $prefix = rtrim('/' . ltrim($controller->prefix, '/'), '/');

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $className !== $method->getDeclaringClass()->getName()) {
                    continue;
                }

                $methodAttributes = $collector->getMethodAttributes($className, $method->getName());

                foreach ($methodAttributes as $attribute) {
                    if (RequestMapping::class !== $attribute->name()) {
                        continue;
                    }

                    /** @var RequestMapping $mapping */
                    $mapping = $attribute->attribute();
                    $path = $prefix . ('' === $mapping->path ? '' : '/' . ltrim($mapping->path, '/'));
                    $path = '' === $path ? '/' : $path;

                    try {
                        $variants = $routeParser->parse($path);
                    } catch (Throwable $throwable) {
                        throw new InvalidHttpDeclarationException(
                            'Unable to build the HTTP route table.',
                            0,
                            $throwable,
                        );
                    }

                    $variables = [];

                    foreach ($variants as $segments) {
                        foreach ($segments as $segment) {
                            if (is_array($segment)) {
                                $variables[$segment[0]] = true;
                            }
                        }
                    }

                    foreach ($method->getParameters() as $parameter) {
                        if (isset($variables[$parameter->getName()])) {
                            RouteValueConverter::assertSupported($parameter);
                        }
                    }

                    $serverRoutes[$controller->server][] = [
                        'method'  => $mapping->method->value,
                        'path'    => $path,
                        'handler' => new RouteDefinition(
                            $className,
                            $method->getName(),
                        ),
                    ];
                }
            }
        }

        $dispatchers = [];

        try {
            foreach ($serverRoutes as $serverName => $routes) {
                $dispatchers[$serverName] = simpleDispatcher(
                    static function (RouteCollector $collector) use ($routes): void {
                        foreach ($routes as $route) {
                            $collector->addRoute($route['method'], $route['path'], $route['handler']);
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
}
