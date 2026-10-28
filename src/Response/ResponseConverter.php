<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Response;

use JsonException;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Stringable;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;

use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class ResponseConverter
{
    /**
     * @throws JsonException                   When the result cannot be encoded as JSON
     * @throws InvalidHttpDeclarationException When the result type is not supported
     */
    public function convert(mixed $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if (null === $result) {
            return ResponseContext::get();
        }

        if (is_array($result) || $result instanceof JsonSerializable) {
            $contentType = 'application/json; charset=utf-8';
            $body = json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } elseif (
            is_string($result)
            || is_int($result)
            || is_float($result)
            || is_bool($result)
            || $result instanceof Stringable
        ) {
            $contentType = 'text/plain; charset=utf-8';
            $body = (string) $result;
        } else {
            throw new InvalidHttpDeclarationException(sprintf(
                'HTTP results must be a PSR-7 response, JSON-compatible data, a scalar, Stringable, or null; %s provided.',
                get_debug_type($result),
            ));
        }

        return ResponseContext::get()
            ->withoutHeader('Content-Length')
            ->withHeader('Content-Type', $contentType)
            ->withBody(new StringStream($body));
    }
}
