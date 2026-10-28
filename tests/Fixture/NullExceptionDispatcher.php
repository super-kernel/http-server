<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use SuperKernel\Contract\ExceptionDispatcherInterface;
use Throwable;

final readonly class NullExceptionDispatcher implements ExceptionDispatcherInterface
{
    public function dispatch(Throwable $throwable): void
    {
    }
}
