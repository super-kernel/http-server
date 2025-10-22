<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use FastRoute\Dispatcher;
use SuperKernel\HttpServer\Collector\RouteData;

final class Dispatched
{
	public int $status;

	public RouteData $handler;

	public array $parameters = [];

	public function __construct(array $routes)
	{
		$this->status = $routes[0];
		switch ($this->status) {
			case Dispatcher::METHOD_NOT_ALLOWED:
				$this->parameters = $routes[1];
				break;
			case Dispatcher::FOUND:
				$this->handler    = $routes[1];
				$this->parameters = $routes[2];
				break;
		}
	}

	public function isFound(): bool
	{
		return $this->status === Dispatcher::FOUND;
	}

	public function isNotFound(): bool
	{
		return $this->status === Dispatcher::NOT_FOUND;
	}

	public function isMethodNotAllowed(): bool
	{
		return $this->status === Dispatcher::METHOD_NOT_ALLOWED;
	}
}