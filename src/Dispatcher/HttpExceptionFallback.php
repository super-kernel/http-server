<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use SuperKernel\HttpServer\Exception\HttpException;
use Throwable;

/**
 * Derives the plain-text fallback for a throwable: an HttpException's own response metadata or a generic 500.
 *
 * @internal
 */
final class HttpExceptionFallback
{
    /**
     * @throws InvalidArgumentException When an HttpException carries an invalid status code or header
     */
    public static function response(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if ($throwable instanceof HttpException) {
            return PlainTextContent::apply(
                $response,
                $throwable->getMessage(),
                $throwable->statusCode,
                $throwable->headers,
            );
        }

        return PlainTextContent::apply($response, 'Internal Server Error', 500);
    }
}
