<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use FastRoute\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Di\Collector\ReflectionCollector;
use SuperKernel\Di\Exception\Exception;
use SuperKernel\HttpServer\Collector\MiddlewareCollector;
use SuperKernel\HttpServer\Collector\RouteCollector;
use SuperKernel\HttpServer\Context\MiddlewareContext;
use SuperKernel\HttpServer\Contract\MiddlewareDispatcherInterface;
use SuperKernel\HttpServer\Exception\MethodNotAllowedHttpException;
use SuperKernel\HttpServer\Exception\NotFoundHttpException;

final class MiddlewareDispatcher implements MiddlewareDispatcherInterface
{
	private array $middlewares = [];

	private Dispatcher $dispatcher;

	public function __construct(
		private readonly RouteCollector      $routeCollector,
		private readonly MiddlewareCollector $middlewareCollector,
		private readonly ReflectionCollector $reflectionCollector,
	)
	{
	}

	public function setServerName(string $serverName): void
	{
		$this->dispatcher  = new Dispatcher\GroupCountBased($this->routeCollector->getRouteCollector($serverName)->getData());
		$this->middlewares = $this->middlewareCollector->getMiddlewares($serverName);
	}

	public function dispatch(ServerRequestInterface $request): ServerRequestInterface
	{
		$routes = $this->dispatcher->dispatch($request->getMethod(), $request->getUri()->getPath());

		$dispatched = new Dispatched($routes);

		$middlewares = $this->middlewares;

		if ($dispatched->isFound()) {
			$middlewares = array_merge($middlewares, $dispatched->handler->middlewares);
		}

		MiddlewareContext::set($this->sortMiddlewares($middlewares));

		return $request
			->withAttribute(Dispatched::class, $dispatched);
	}

	/**
	 * @param ServerRequestInterface  $request
	 * @param RequestHandlerInterface $handler
	 *
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		/* @var Dispatched $dispatched */
		$dispatched = $request->getAttribute(Dispatched::class);

		if (!$dispatched instanceof Dispatched) {
			throw new Exception(sprintf('The dispatched object is not a %s object.', Dispatched::class));
		}

		return match ($dispatched->status) {
			Dispatcher::NOT_FOUND          => $this->handleNotFound(),
			Dispatcher::FOUND              => $this->handleFound($dispatched),
			Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed($dispatched->parameters),
		};
	}

	private function handleNotFound()
	{
		throw new NotFoundHttpException();
	}

	/**
	 * @param array $methods
	 *
	 * @return mixed
	 */
	private function handleMethodNotAllowed(array $methods): mixed
	{
		throw new MethodNotAllowedHttpException('Allow: ' . implode(', ', $methods));
	}

	/**
	 * Provide container injection capabilities during request processing, but avoid exceptions that may occur in some
	 * scenarios. This requirement should be implemented and improved in the next version.
	 *
	 * @param Dispatched $dispatched
	 *
	 * @return mixed
	 */
	private function handleFound(Dispatched $dispatched): mixed
	{
		$arguments  = [];
		$handler    = $dispatched->handler;
		$parameters = $this->reflectionCollector->reflectMethod($handler->controller, $handler->action)->getParameters();

		foreach ($parameters as $parameter) {
			$name = $parameter->getName();
			if (!isset($dispatched->parameters[$name])) {
				throw new RuntimeException(sprintf('The route parameter "%s" does not exist.', $parameter->getName()));
			}

			$arguments[] = $dispatched->parameters[$name];
		}

		return $dispatched->handler->object->{$handler->action}(...$arguments);
	}

	private function sortMiddlewares(array $middlewares): SplPriorityQueue
	{
		$middleware = new SplPriorityQueue;

		foreach ($middlewares as $item) {
			$middleware->insert($item[0], $item[1]);
		}

		return $middleware;
	}
}