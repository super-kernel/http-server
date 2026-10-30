<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use SuperKernel\HttpMessage\Stream\StringStream;

/**
 * Applies a status, a UTF-8 plain-text content type, extra headers, and a string body to a PSR response.
 *
 * @internal
 */
final class PlainTextContent
{
    /**
     * @param array<non-empty-string, string|string[]> $headers applied after the content type in declaration order
     *
     * @throws InvalidArgumentException When the status code or a header is invalid
     */
    public static function apply(
        ResponseInterface $response,
        string            $body,
        int               $status,
        array             $headers = [],
    ): ResponseInterface
    {
        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');

        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody(new StringStream($body));
    }
}
