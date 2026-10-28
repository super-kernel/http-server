<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;
use SuperKernel\Contract\StdoutLoggerInterface;

final class NullStdoutLogger extends AbstractLogger implements StdoutLoggerInterface
{
    public function log($level, string|Stringable $message, array $context = []): void
    {
    }
}
