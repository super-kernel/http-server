<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Controller
{
    /**
     * @param non-empty-string $server
     */
    public function __construct(public string $prefix = '', public string $server = 'http')
    {
        if ('' === $server) {
            throw new InvalidArgumentException('HTTP controller server name must be non-empty.');
        }
    }
}
