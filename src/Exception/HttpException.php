<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Exception;

use RuntimeException;

final class HttpException extends RuntimeException implements HttpServerExceptionInterface
{
    /**
     * @param array<non-empty-string, string|string[]> $headers
     */
    public function __construct(
        public readonly int   $statusCode,
        string                $message,
        public readonly array $headers = [],
    )
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(404, 'Not Found');
    }

    /**
     * Creates a 405 exception for method restrictions enforced by application code.
     *
     * @api
     *
     * @param list<non-empty-string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(405, 'Method Not Allowed', [
            'Allow' => $allowedMethods,
        ]);
    }
}
