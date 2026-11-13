<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Routing;

use BackedEnum;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;

use function enum_exists;
use function filter_var;
use function in_array;
use function is_a;
use function is_numeric;
use function sprintf;
use function strtolower;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOL;
use const FILTER_VALIDATE_INT;

/**
 * Converts one route variable to the scalar or backed-enum type declared by its controller action parameter.
 *
 * @internal
 */
final class RouteValueConverter
{
    /**
     * Rejects a parameter whose declared type no route value can ever be converted to.
     *
     * @throws InvalidHttpDeclarationException When the parameter has a named type other than string, mixed, int,
     *                                         float, bool, or a backed enum
     */
    public static function assertSupported(ReflectionParameter $parameter): void
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return;
        }

        $name = $type->getName();

        if (
            in_array($name, [
                'string',
                'mixed',
                'int',
                'float',
                'bool',
            ], true)
            || (!$type->isBuiltin() && enum_exists($name) && is_a($name, BackedEnum::class, true))
        ) {
            return;
        }

        throw new InvalidHttpDeclarationException(sprintf(
            'Route parameter $%s of %s::%s() has type %s, which cannot receive a route value.',
            $parameter->getName(),
            $parameter->getDeclaringClass()?->getName() ?? '',
            $parameter->getDeclaringFunction()->getName(),
            $type,
        ));
    }

    /**
     * Returns the value unchanged for string, mixed, and non-named parameter types.
     *
     * @throws InvalidHttpDeclarationException When the parameter's type cannot receive a route value, or a declared
     *                                         backed enum cannot be reflected
     * @throws HttpException                   With status 400 when the value cannot be converted to the declared type
     */
    public static function convert(string $value, ReflectionParameter $parameter, ReflectorInterface $reflector): mixed
    {
        self::assertSupported($parameter);

        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $name = $type->getName();

        if ('string' === $name || 'mixed' === $name) {
            return $value;
        }

        if ('int' === $name) {
            $converted = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        } elseif ('float' === $name) {
            $converted = is_numeric($value) ? (float) $value : null;
        } elseif ('bool' === $name) {
            $converted = filter_var(strtolower($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            /** @var class-string<BackedEnum> $enum */
            $enum = $name;

            try {
                $backingType = $reflector->reflectEnum($enum)->getBackingType()?->getName();
            } catch (ReflectionException $exception) {
                throw new InvalidHttpDeclarationException(sprintf(
                    'Unable to reflect HTTP route enum %s.',
                    $enum,
                ), 0, $exception);
            }

            $backedValue = 'int' === $backingType
                ? filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
                : $value;
            $converted = null === $backedValue ? null : $enum::tryFrom($backedValue);
        }

        if (null === $converted) {
            // The message is the response body without a handler, so it never repeats the request's value.
            throw new HttpException(400, 'Bad Request');
        }

        return $converted;
    }
}
