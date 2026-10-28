<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Psr\Http\Message\ResponseInterface;
use Throwable;

interface HttpExceptionHandlerInterface
{
    /**
     * Returning a response alone does not stop exception propagation.
     *
     * @return ResponseInterface The response passed to the next listener-bound handler
     */
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface;
}
