<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Router;

use FastRoute\Dispatcher;
use SplPriorityQueue;

final class Dispatched
{
	public int $status;

	public RouteData $handler;

	public array $parameters = [];

	public SplPriorityQueue $middleware;

	public function __construct(array $routes, array $middlewares)
	{
		switch ($this->status = $routes[0]) {
			case Dispatcher::NOT_FOUND:
				break;
			case Dispatcher::METHOD_NOT_ALLOWED:
				$this->parameters = $routes[1];
				break;
			case Dispatcher::FOUND:
				$this->handler    = $routes[1];
				$this->parameters = $routes[2];
				$middlewares      = $middlewares + $this->handler->middlewares;
				break;
		}

		$this->middleware = new SplPriorityQueue();

		foreach ($middlewares as [$middleware, $priority]) {
			$this->middleware->insert($middleware, $priority);
		}
	}
}