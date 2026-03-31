<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Router;

use SplPriorityQueue;

final readonly class RouteData
{
	private SplPriorityQueue $middlewares;

	public function __construct(
		private string $controller,
		private string $action,
		array          $middlewares,
	)
	{
		$this->middlewares = new SplPriorityQueue();

		foreach ($middlewares as $middleware) {
			$this->middlewares->insert($middleware, 0);
		}
	}

	public function getController(): string
	{
		return $this->controller;
	}

	public function getAction(): string
	{
		return $this->action;
	}

	public function getMiddlewares(): SplPriorityQueue
	{
		return $this->middlewares;
	}
}