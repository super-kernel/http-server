<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;
use InvalidArgumentException;

use function count;
use function is_string;
use function str_contains;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Middlewares
{
    /**
     * @var list<class-string>
     */
    public array $middlewares;

    /**
     * @param list<class-string> $middlewares
     */
    public function __construct(array $middlewares)
    {
        $normalized = [];

        foreach ($middlewares as $position => $className) {
            if (
                $position !== count($normalized)
                || !is_string($className)
                || '' === $className
                || str_contains($className, "\0")
            ) {
                throw new InvalidArgumentException(
                    'Middlewares must be a list of non-empty class names without NUL bytes.',
                );
            }

            $normalized[] = $className;
        }

        $this->middlewares = $normalized;
    }
}
