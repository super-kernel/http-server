<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

interface MiddlewareCollectorInterface
{
	public function getServerMiddlewares(string $serverName): array;

	public function getControllerMiddlewares(string $class): array;

	public function getActionMiddlewares(string $class, string $method): array;
}