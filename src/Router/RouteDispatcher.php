<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Router;

use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;

final class RouteDispatcher extends GroupCountBased
{
	public function __construct(RouteCollector $routeCollector)
	{
		parent::__construct($routeCollector->getData());
	}
}