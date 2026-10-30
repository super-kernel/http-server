<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Response;

use SuperKernel\Contract\StdoutLoggerInterface;
use Throwable;

/**
 * Logs a response-emission failure as an error without letting a logger failure escape.
 *
 * @internal
 */
final class EmissionFailureLog
{
    /**
     * Logs with an empty context unless a throwable is supplied, which is passed as the exception context entry.
     */
    public static function write(StdoutLoggerInterface $logger, string $message, ?Throwable $throwable = null): void
    {
        try {
            if (null === $throwable) {
                $logger->error($message);

                return;
            }

            $logger->error($message, [
                'exception' => $throwable,
            ]);
        } catch (Throwable) {
        }
    }
}
