<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;
<<<<<<< HEAD
use SuperKernel\Attribute\Contract;
=======
>>>>>>> main
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;

#[
<<<<<<< HEAD
	Contract(Dispatcher::class),
=======
>>>>>>> main
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