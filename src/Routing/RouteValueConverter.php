<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Routing;

use BackedEnum;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
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

/**
 * Converts one route variable to the scalar or backed-enum type declared by its controller action parameter.
 *
 * @internal
 */
final class RouteValueConverter
{
    /**
     * Returns the value unchanged for string, mixed, and non-named parameter types.
     *
     * @throws InvalidHttpDeclarationException When the value cannot be converted to the declared type, or a declared
     *                                         backed enum cannot be reflected or has an unsupported backing type
     */
    public static function convert(string $value, ReflectionParameter $parameter, ReflectorInterface $reflector): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $name = $type->getName();

        if (!$type->isBuiltin() && enum_exists($name) && is_a($name, BackedEnum::class, true)) {
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

            if ('int' === $backingType) {
                $backedValue = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

                if (!is_int($backedValue)) {
                    throw new InvalidHttpDeclarationException(sprintf(
                        'Route value %s cannot be converted for parameter $%s.',
                        $value,
                        $parameter->getName(),
                    ));
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
        } elseif ('string' === $name || 'mixed' === $name) {
            return $value;
        } elseif ('int' === $name) {
            $converted = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        } elseif ('float' === $name) {
            $converted = is_numeric($value) ? (float) $value : null;
        } elseif ('bool' === $name) {
            $converted = filter_var(strtolower($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } else {
            $converted = null;
        }

        if (null === $converted) {
            throw new InvalidHttpDeclarationException(sprintf(
                'Route value %s cannot be converted for parameter $%s.',
                $value,
                $parameter->getName(),
            ));
        }

        return $converted;
    }
}
