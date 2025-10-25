<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use Psr\Container\ContainerInterface;
use SuperKernel\HttpServer\CallbackEvent\OnRequestEvent;
use SuperKernel\HttpServer\Collector\MiddlewareCollector;
use SuperKernel\HttpServer\Collector\RouteCollector;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherFactoryInterface;
use SuperKernel\HttpServer\Dispatcher\MiddlewareDispatcher;
use SuperKernel\HttpServer\Dispatcher\RequestHandler;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class OnRequestEventFactory
{
	private ?ExceptionDispatcherFactoryInterface $exceptionDispatcherFactory = null {
		get => $this->exceptionDispatcherFactory = $this->container->get(ExceptionDispatcherFactoryInterface::class);
	}

	private ?RouteCollector $routeCollector = null {
		get => $this->routeCollector ??= $this->container->get(RouteCollector::class);
	}

	private ?MiddlewareCollector $middlewareCollector = null {
		get => $this->middlewareCollector ??= $this->container->get(MiddlewareCollector::class);
	}

	public function __construct(private readonly ContainerInterface $container)
	{
	}

	public function getEventCallback(string $serverName): callable
	{
		$middlewareDispatcher = $this->getMiddlewareDispatcher($serverName);
		$requestHandler       = new RequestHandler($middlewareDispatcher);
		$exceptionDispatcher  = $this->exceptionDispatcherFactory->getDispatcher($serverName);
		$onRequestEvent       = new OnRequestEvent(
			requestHandler      : $requestHandler,
			exceptionDispatcher : $exceptionDispatcher,
			middlewareDispatcher: $middlewareDispatcher,
		);

		return fn(Request $request, Response $response) => $onRequestEvent->handle($request, $response);
	}

	private function getDispatcher(string $serverName): Dispatcher
	{
		return new GroupCountBased($this->routeCollector->getRouteCollector($serverName)->getData());
	}

	private function getMiddlewareDispatcher(string $serverName): MiddlewareDispatcher
	{
		$middlewares = $this->middlewareCollector->getMiddlewares($serverName);
		$dispatcher  = $this->getDispatcher($serverName);

		return new MiddlewareDispatcher(
			container  : $this->container,
			dispatcher : $dispatcher,
			middlewares: $middlewares,
		);
	}
}