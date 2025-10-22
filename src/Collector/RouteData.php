<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Collector;

final readonly class RouteData
{
	public function __construct(public mixed $handler, public array $middlewares = [])
	{
	}
}