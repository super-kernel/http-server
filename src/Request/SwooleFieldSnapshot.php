<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Request;

use InvalidArgumentException;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;

use function get_debug_type;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Recursively copies one native Swoole request field into a detached array of scalars, nulls, and nested arrays.
 *
 * @internal
 */
final class SwooleFieldSnapshot
{
    /**
     * @param array<array-key, mixed> $values
     * @param string                  $field  the field path used in failure messages, such as server or get[filter]
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException When a value is a native Swoole HTTP object or another non-scalar, non-array
     */
    public static function copy(array $values, string $field): array
    {
        $snapshot = [];

        foreach ($values as $key => $value) {
            $path = sprintf('%s[%s]', $field, $key);

            if ($value instanceof Request || $value instanceof SwooleResponse) {
                throw new InvalidArgumentException(sprintf(
                    'Swoole request field %s must not contain native Swoole HTTP objects.',
                    $path,
                ));
            }

            if (is_array($value)) {
                $snapshot[$key] = self::copy($value, $path);

                continue;
            }

            if (null !== $value && !is_scalar($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Swoole request field %s must contain only scalar, null, or nested array values; got %s.',
                    $path,
                    get_debug_type($value),
                ));
            }

            $snapshot[$key] = $value;
        }

        return $snapshot;
    }
}
