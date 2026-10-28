<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Declares global or named-server PSR-15 middleware.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Middleware
{
    /**
     * @param non-empty-string|null $name
     */
    public function __construct(public ?string $name = null)
    {
        if ('' === $name) {
            throw new InvalidArgumentException('HTTP middleware server name must be non-empty when provided.');
        }
    }
}
