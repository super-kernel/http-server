<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use Psr\Http\Message\ResponseInterface;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface;
use Throwable;

final readonly class RecordingHttpExceptionHandler implements StoppableHttpExceptionHandlerInterface
{
    public function __construct(private InvocationLog $log)
    {
    }

    public function handle(
        Throwable         $throwable,
        ResponseInterface $response,
    ): ResponseInterface
    {
        $this->log->entries[] = 'exception:handler';

        return new Response(statusCode: 418, body: 'handled');
    }

    public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
    {
        return true;
    }
}
