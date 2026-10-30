<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Routing;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;

use function is_a;
use function sprintf;

final readonly class ControllerArgumentResolver
{
    public function __construct(private ContainerInterface $container, private ReflectorInterface $reflector)
    {
    }

    /**
     * PHP can still raise ReflectionException while loading an internal function's declared default after
     * isDefaultValueAvailable() returns true; PhpStorm's guard contract does not model that case.
     *
     * @param array<string, string> $routeVariables
     *
     * @return list<mixed>
     *
     * @throws NotFoundExceptionInterface      When an action dependency is not registered
     * @throws ContainerExceptionInterface     When an action dependency cannot be resolved
     * @throws InvalidHttpDeclarationException When action reflection or HTTP argument binding fails
     *
     * @noinspection PhpRedundantCatchClauseInspection
     */
    public function resolve(
        RouteDefinition        $route,
        ServerRequestInterface $request,
        array                  $routeVariables,
    ): array
    {
        $arguments = [];

        try {
            $parameters = $this->reflector->reflectMethod($route->controller, $route->action)->getParameters();
        } catch (ReflectionException $exception) {
            throw new InvalidHttpDeclarationException(sprintf(
                'Unable to reflect HTTP controller action %s::%s().',
                $route->controller,
                $route->action,
            ), 0, $exception);
        }

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (isset($routeVariables[$name])) {
                $arguments[] = RouteValueConverter::convert($routeVariables[$name], $parameter, $this->reflector);

                continue;
            }

            $namedTypes = $type instanceof ReflectionUnionType ? $type->getTypes() : [
                $type,
            ];

            foreach ($namedTypes as $namedType) {
                if (!$namedType instanceof ReflectionNamedType || $namedType->isBuiltin()) {
                    continue;
                }

                $className = $namedType->getName();

                if (is_a($request, $className)) {
                    $arguments[] = $request;

                    continue 2;
                }

                if (!$type instanceof ReflectionUnionType) {
                    $arguments[] = $this->container->get($className);

                    continue 2;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                try {
                    $arguments[] = $parameter->getDefaultValue();
                } catch (ReflectionException $exception) {
                    throw new InvalidHttpDeclarationException(sprintf(
                        'Unable to read the default value of parameter $%s for %s::%s().',
                        $parameter->getName(),
                        $route->controller,
                        $route->action,
                    ), 0, $exception);
                }

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new InvalidHttpDeclarationException(sprintf(
                'Unable to resolve parameter $%s of %s::%s().',
                $name,
                $route->controller,
                $route->action,
            ));
        }

        return $arguments;
    }
}
