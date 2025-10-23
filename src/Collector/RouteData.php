<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Collector;

final readonly class RouteData
{
	public function __construct(
		public string $controller,
		public string $action,
		public object $object,
		public array  $middlewares = [],
	)
	{
	}
}