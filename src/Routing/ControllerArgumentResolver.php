<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Routing;

use BackedEnum;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;

use function enum_exists;
use function filter_var;
use function is_a;
use function is_int;
use function is_numeric;
use function sprintf;
use function strtolower;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOL;
use const FILTER_VALIDATE_INT;

final readonly class ControllerArgumentResolver
{
    public function __construct(private ContainerInterface $container, private ReflectorInterface $reflector)
    {
    }

    /**
     * @param array<string, string> $routeVariables
     *
     * @return list<mixed>
     *
     * @throws NotFoundExceptionInterface      When an action dependency is not registered
     * @throws ContainerExceptionInterface     When an action dependency cannot be resolved
     * @throws InvalidHttpDeclarationException When action reflection or HTTP argument binding fails
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
            $arguments[] = $this->resolveParameter($parameter, $request, $route, $routeVariables);
        }

        return $arguments;
    }

    /**
     * @param array<string, string> $routeVariables
     *
     * @throws NotFoundExceptionInterface      When an action dependency is not registered
     * @throws ContainerExceptionInterface     When an action dependency cannot be resolved
     * @throws InvalidHttpDeclarationException When HTTP argument binding fails
     */
    private function resolveParameter(
        ReflectionParameter    $parameter,
        ServerRequestInterface $request,
        RouteDefinition        $route,
        array                  $routeVariables,
    ): mixed
    {
        $name = $parameter->getName();
        $type = $parameter->getType();

        if (isset($routeVariables[$name])) {
            return $this->routeValue($routeVariables[$name], $parameter);
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
                return $request;
            }

            if (!$type instanceof ReflectionUnionType) {
                return $this->container->get($className);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return self::defaultValue($parameter, $route);
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new InvalidHttpDeclarationException(sprintf(
            'Unable to resolve parameter $%s of %s::%s().',
            $name,
            $route->controller,
            $route->action,
        ));
    }

    private function routeValue(string $value, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $name = $type->getName();

        if (!$type->isBuiltin() && enum_exists($name) && is_a($name, BackedEnum::class, true)) {
            /** @var class-string<BackedEnum> $enum */
            $enum = $name;

            return $this->enumRouteValue($enum, $value, $parameter);
        }

        if ('string' === $name || 'mixed' === $name) {
            return $value;
        }

        if ('int' === $name) {
            return self::validatedRouteValue(
                filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
                $value,
                $parameter,
            );
        }

        if ('float' === $name) {
            if (!is_numeric($value)) {
                self::throwInvalidRouteValue($value, $parameter);
            }

            return (float) $value;
        }

        if ('bool' === $name) {
            return self::validatedRouteValue(
                filter_var(strtolower($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                $value,
                $parameter,
            );
        }

        self::throwInvalidRouteValue($value, $parameter);
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    private function enumRouteValue(
        string              $enum,
        string              $value,
        ReflectionParameter $parameter,
    ): BackedEnum
    {
        try {
            $backingType = $this->reflector->reflectEnum($enum)->getBackingType()?->getName();
        } catch (ReflectionException $exception) {
            throw new InvalidHttpDeclarationException(sprintf(
                'Unable to reflect HTTP route enum %s.',
                $enum,
            ), 0, $exception);
        }

        if ('int' === $backingType) {
            $backedValue = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

            if (!is_int($backedValue)) {
                self::throwInvalidRouteValue($value, $parameter);
            }
        } elseif ('string' === $backingType) {
            $backedValue = $value;
        } else {
            throw new InvalidHttpDeclarationException(sprintf(
                'HTTP route enum %s does not declare a supported backing type.',
                $enum,
            ));
        }

        $converted = $enum::tryFrom($backedValue);

        if (null === $converted) {
            self::throwInvalidRouteValue($value, $parameter);
        }

        return $converted;
    }

    private static function validatedRouteValue(
        int|bool|null       $converted,
        string              $value,
        ReflectionParameter $parameter,
    ): int|bool
    {
        if (null === $converted) {
            self::throwInvalidRouteValue($value, $parameter);
        }

        return $converted;
    }

    private static function defaultValue(ReflectionParameter $parameter, RouteDefinition $route): mixed
    {
        try {
            return $parameter->getDefaultValue();
        } catch (ReflectionException $exception) {
            throw new InvalidHttpDeclarationException(sprintf(
                'Unable to read the default value of parameter $%s for %s::%s().',
                $parameter->getName(),
                $route->controller,
                $route->action,
            ), 0, $exception);
        }
    }

    private static function throwInvalidRouteValue(string $value, ReflectionParameter $parameter): never
    {
        throw new InvalidHttpDeclarationException(sprintf(
            'Route value %s cannot be converted for parameter $%s.',
            $value,
            $parameter->getName(),
        ));
    }
}
