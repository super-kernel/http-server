<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use SuperKernel\Contract\ExceptionDispatcherInterface;
use Throwable;

final class RecordingExceptionDispatcher implements ExceptionDispatcherInterface
{
    /** @var list<Throwable> */
    public array $dispatches = [];

    public function dispatch(Throwable $throwable): void
    {
        $this->dispatches[] = $throwable;
    }
}
