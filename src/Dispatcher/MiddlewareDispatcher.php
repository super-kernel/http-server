<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Di\Contract\ResolverFactoryInterface;
use SuperKernel\Di\Definition\ParameterDefinition;
use SuperKernel\Di\Exception\Exception;
use SuperKernel\HttpServer\Context\MiddlewareContext;
use SuperKernel\HttpServer\Contract\MiddlewareDispatcherInterface;
use SuperKernel\HttpServer\Exception\MethodNotAllowedHttpException;
use SuperKernel\HttpServer\Exception\NotFoundHttpException;

final class MiddlewareDispatcher implements MiddlewareDispatcherInterface
{
	private ?ResolverFactoryInterface $resolverDispatcher = null {
		get => $this->resolverDispatcher ??= $this->container->get(ResolverFactoryInterface::class);
	}

	private ?ResponseInterface $response = null {
		get => $this->response ??= $this->container->get(ResponseInterface::class);
	}

	public function __construct(
		private readonly ContainerInterface $container,
		private readonly Dispatcher         $dispatcher,
		private readonly array              $middlewares = [],
	)
	{
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
	 * @waring Route discovery is handled by the parameter resolver in `super-kernel/di`. If another DI container is
	 *         used, remap the provider of `SuperKernel\HttpServer\Contract\MiddlewareDispatcherInterface`.
	 *
	 * @param Dispatched $dispatched
	 *
	 * @return mixed
	 */
	private function handleFound(Dispatched $dispatched): mixed
	{
		$handler             = $dispatched->handler;
		$parameterDefinition = new ParameterDefinition($handler->controller, $handler->action, $dispatched->parameters);
		$arguments           = $this->resolverDispatcher->getResolver($parameterDefinition)->resolve($parameterDefinition);

		$response = $dispatched->handler->object->{$handler->action}(...$arguments);

		if ($response instanceof ResponseInterface) {
			return $response;
		}

		throw new RuntimeException('The requested object does not implement ResponseInterface.');
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