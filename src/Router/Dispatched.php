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

	public function __construct(array $routes)
	{
		switch ($this->status = $routes[0]) {
			case Dispatcher::NOT_FOUND:
				break;
			case Dispatcher::METHOD_NOT_ALLOWED:
				$this->parameters = $routes[1];
				break;
			case Dispatcher::FOUND:
				$this->handler = $routes[1];
				$this->parameters = $routes[2];
				$this->middleware = $this->handler->getMiddlewares();
				break;
		}
	}
}