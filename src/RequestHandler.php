<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherFactoryInterface;
use SuperKernel\HttpServer\Router\Dispatched;
use SuperKernel\HttpServer\Router\MiddlewareCollector;
use SuperKernel\HttpServer\Router\RouteDispatcher;
use Throwable;

final readonly class RequestHandler implements RequestHandlerInterface
{
	private string $serverName;

	private ExceptionDispatcher $exceptionDispatcher;

	public function __construct(
		private RouteDispatcher             $routeDispatcher,
		private MiddlewareInterface         $middleware,
		private MiddlewareCollector         $middlewareCollector,
		ExceptionDispatcherFactoryInterface $exceptionDispatcherFactory,
	)
	{
		$this->serverName = $this->routeDispatcher->serverName;

		$this->exceptionDispatcher = $exceptionDispatcherFactory->getDispatcher($this->serverName);
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		try {
			$dispatched = $request->getAttribute(Dispatched::class);

			if (null === $dispatched) {
				$middlewares = $this->middlewareCollector->getMiddlewares($this->serverName);

				$routes = $this->routeDispatcher->dispatch(
					$request->getMethod(),
					$request->getUri()->getPath(),
				);

				$dispatched = new Dispatched($routes, $middlewares);

				$request = $request->withAttribute(Dispatched::class, $dispatched);
			}

			return $this->middleware->process($request, $this);
		}
		catch (Throwable $throwable) {
			return $this->exceptionDispatcher->handle($throwable, ResponseContext::get());
		}
	}
}