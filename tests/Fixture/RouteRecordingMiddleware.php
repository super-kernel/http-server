<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RouteRecordingMiddleware implements MiddlewareInterface
{
    public function __construct(private InvocationLog $log)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log->entries[] = 'route:before';
        $response = $handler->handle($request);
        $this->log->entries[] = 'route:after';

        return $response;
    }
}
