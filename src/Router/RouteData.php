<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Router;

final readonly class RouteData
{
	public function __construct(public string $controller, public string $action, public array $middlewares)
	{
	}
}