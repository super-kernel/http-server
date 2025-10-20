<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class MiddlewareDispatcher
{
	public function __construct(private \SplPriorityQueue $middlewares)
	{
	}

	public function dispatch(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		while (!$this->middlewares->isEmpty()) {
			$middleware = $this->middlewares->top();
			if (!($middleware instanceof MiddlewareInterface)) {
				throw new RuntimeException('The MiddlewareProvider not implemented');
			}

			$response = $middleware->process($request, $handler);
		}
	}
}