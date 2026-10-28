<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class GlobalRecordingMiddleware implements MiddlewareInterface
{
    public function __construct(private InvocationLog $log)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->entries[] = 'global:before';
        $response = $handler->handle($request);
        $this->log->entries[] = 'global:after';

        return $response->withHeader('X-Global-Middleware', 'yes');
    }
}
