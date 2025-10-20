<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;

#[
	Provider(Dispatcher::class),
	Factory,
]
final readonly class RouteDispatcherProvider
{
	public function __invoke(RouteCollector $routeCollector): Dispatcher
	{
		return new GroupCountBased($routeCollector->getData());
	}
}