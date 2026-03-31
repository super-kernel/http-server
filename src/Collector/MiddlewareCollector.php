<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Collector;

use Psr\Http\Server\MiddlewareInterface;
use SplPriorityQueue;
use SuperKernel\HttpServer\Contract\MiddlewareCollectorInterface;

final readonly class MiddlewareCollector implements MiddlewareCollectorInterface
{
	private array $serverMiddlewares;

	private array $controllerMiddlewares;

	private array $actionMiddlewares;

	/**
	 * @var array<string, SplPriorityQueue<MiddlewareInterface>>                $serverMiddlewares
	 * @var array<string, SplPriorityQueue<MiddlewareInterface>>                $controllerMiddlewares
	 * @var array<string, array<string, SplPriorityQueue<MiddlewareInterface>>> $actionMiddlewares
	 */
	public function __construct(array $serverMiddlewares, array $controllerMiddlewares, array $actionMiddlewares)
	{
		$serverMiddlewareContainers = [];
		foreach ($serverMiddlewares as $serverName => $serverMiddleware) {
			foreach ($serverMiddleware as $middleware) {
				$serverMiddlewareContainers[$serverName][] = $middleware;
			}
		}
		$this->serverMiddlewares = $serverMiddlewareContainers;

		$controllerMiddlewareContainers = [];
		foreach ($controllerMiddlewares as $class => $controllerMiddleware) {
			foreach ($controllerMiddleware as $middleware) {
				$controllerMiddlewareContainers[$class][] = $middleware;
			}
		}
		$this->controllerMiddlewares = $controllerMiddlewareContainers;

		$actionMiddlewareContainers = [];
		foreach ($actionMiddlewares as $class => $methodMiddlewares) {
			foreach ($methodMiddlewares as $action => $middlewares) {
				foreach ($middlewares as $middleware) {
					$actionMiddlewareContainers[$class][$action][] = $middleware;
				}
			}
		}
		$this->actionMiddlewares = $actionMiddlewareContainers;
	}

	public function getServerMiddlewares(string $serverName): array
	{
		return $this->serverMiddlewares[$serverName] ?? [];
	}

	public function getControllerMiddlewares(string $class): array
	{
		return $this->controllerMiddlewares[$class] ?? [];
	}

	public function getActionMiddlewares(string $class, string $method): array
	{
		return $this->actionMiddlewares[$class][$method] ?? [];
	}
}