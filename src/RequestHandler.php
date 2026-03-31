<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use FastRoute\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\Router\Dispatched;
use Throwable;

final readonly class RequestHandler implements RequestHandlerInterface
{
	public function __construct(
		private MiddlewareInterface $middleware,
		private Dispatcher          $routeDispatcher,
		private ExceptionDispatcher $exceptionDispatcher,
	)
	{
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		try {
			$dispatched = $request->getAttribute(Dispatched::class);

			if (null === $dispatched) {
				$routes = $this->routeDispatcher->dispatch(
					$request->getMethod(),
					$request->getUri()->getPath(),
				);

				$dispatched = new Dispatched($routes);

				$request = $request->withAttribute(Dispatched::class, $dispatched);
			}

			return $this->middleware->process($request, $this);
		}
		catch (Throwable $throwable) {
			return $this->exceptionDispatcher->handle($throwable, ResponseContext::get());
		}
	}
}