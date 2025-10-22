<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;
<<<<<<< Updated upstream
<<<<<<< HEAD

=======

use FastRoute\Dispatcher;
>>>>>>> Stashed changes
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
<<<<<<< Updated upstream
use RuntimeException;
=======
>>>>>>> main

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use SplPriorityQueue;

final readonly class MiddlewareDispatcher
{
<<<<<<< HEAD
	public function __construct(private \SplPriorityQueue $middlewares)
=======
	public function __construct(private SplPriorityQueue $middlewares)
>>>>>>> main
	{
	}

	public function dispatch(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		while (!$this->middlewares->isEmpty()) {
			$middleware = $this->middlewares->top();
			if (!($middleware instanceof MiddlewareInterface)) {
				throw new RuntimeException('The MiddlewareProvider not implemented');
			}

			$response = $middleware->process($request, $handler);
		}
=======
use SplPriorityQueue;
use SuperKernel\Di\Exception\Exception;
use SuperKernel\HttpServer\Collector\MiddlewareCollector;
use SuperKernel\HttpServer\Collector\RouteCollector;
use SuperKernel\HttpServer\Context\MiddlewareContext;
use SuperKernel\HttpServer\Exception\NotFoundHttpException;

final class MiddlewareDispatcher implements MiddlewareInterface
{
	private array $middlewares = [];

	private Dispatcher $dispatcher;

	public function __construct(
		private readonly RouteCollector      $routeCollector,
		private readonly MiddlewareCollector $middlewareCollector,
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
			Dispatcher::FOUND              => $this->handleFound($dispatched, $request),
			Dispatcher::NOT_FOUND          => $this->handleNotFound(),
			Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed($dispatched->parameters, $request),
		};
	}

	private function handleNotFound()
	{
		throw new NotFoundHttpException();
	}

	/**
	 * @param array                  $methods
	 * @param ServerRequestInterface $request
	 *
	 * @return mixed
	 * @throws Exception
	 */
	private function handleMethodNotAllowed(array $methods, ServerRequestInterface $request): mixed
	{
		throw new Exception('Allow: ' . implode(', ', $methods));
	}

	private function handleFound(Dispatched $dispatched, ServerRequestInterface $request)
	{
		[
			$controller,
			$action,
		] = $dispatched->handler->handler;

		return $controller->{$action}();
	}

	private function sortMiddlewares(array $middlewares): SplPriorityQueue
	{
		$middleware = new SplPriorityQueue;

		foreach ($middlewares as $item) {
			$middleware->insert($item[0], $item[1]);
		}

		return $middleware;
>>>>>>> Stashed changes
	}
}