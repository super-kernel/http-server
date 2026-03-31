<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use SuperKernel\HttpServer\Router\RouteDispatcher;

final readonly class DispatcherFactory
{
	/**
	 * @var array<string, RouteCollector> $containers
	 */
	public function __construct(private array $containers)
	{
	}

	public function getDispatcher(string $serverName): Dispatcher
	{
		$routeCollector = $this->getRouteCollector($serverName);

		return new RouteDispatcher($routeCollector);
	}

	private function getRouteCollector(string $serverName): RouteCollector
	{
		if (!isset($this->containers[$serverName])) {
			return new RouteCollector(new Std(), new GroupCountBased());
		}
		return $this->containers[$serverName];
	}
}