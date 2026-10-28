<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Psr\Http\Message\ResponseInterface;
use Throwable;

interface StoppableHttpExceptionHandlerInterface extends HttpExceptionHandlerInterface
{
    /**
     * Called after handle() with the original throwable and the returned response.
     * If no handler stops propagation, dispatch rethrows the original throwable.
     *
     * @return bool True to complete HTTP recovery with this response; false to continue the handler chain
     */
    public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool;
}
