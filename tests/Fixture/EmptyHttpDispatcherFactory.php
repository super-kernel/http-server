<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerProvider;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use Throwable;

final class EmptyHttpDispatcherFactory
{
    /** @throws Throwable When fixture registration fails */
    public static function create(): HttpExceptionDispatcherFactory
    {
        return new HttpExceptionDispatcherFactory(
            new ExceptionHandlerProvider(new TestAnnotationCollector(), new TestContainer([])),
            new RecordingExceptionDispatcher(),
        );
    }
}
