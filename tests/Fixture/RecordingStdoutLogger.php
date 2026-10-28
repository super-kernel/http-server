<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;
use SuperKernel\Contract\StdoutLoggerInterface;

final class RecordingStdoutLogger extends AbstractLogger implements StdoutLoggerInterface
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
