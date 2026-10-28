<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;
use InvalidArgumentException;
use SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler;
use Throwable;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HttpExceptionHandler extends ExceptionHandler
{
    /**
     * @param non-empty-string        $name
     * @param class-string<Throwable> $exception
     */
    public function __construct(public string $name = 'http', int $priority = 0, string $exception = Throwable::class)
    {
        if ('' === $name) {
            throw new InvalidArgumentException('HTTP exception-handler service name must be non-empty.');
        }

        parent::__construct($exception, $priority);
    }
}
