<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use FastRoute\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\Di\Attribute\Provider;
use SuperKernel\Di\Contract\ResolverFactoryInterface;
use SuperKernel\Di\Definition\ParameterDefinition;
use SuperKernel\Di\Exception\Exception;
use SuperKernel\HttpServer\Exception\MethodNotAllowedHttpException;
use SuperKernel\HttpServer\Exception\NotFoundHttpException;
use SuperKernel\HttpServer\Router\Dispatched;
use SuperKernel\Stream\JsonStream;
use SuperKernel\Stream\StandardStream;
use function is_array;

#[
	Provider(MiddlewareInterface::class),
]
final readonly class Middleware implements MiddlewareInterface
{
	public function __construct(
		private ResponseInterface        $response,
		private ResolverFactoryInterface $resolverDispatcher,
	)
	{
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

		$middlewareQueue = $dispatched->middleware;

		if (false === $middlewareQueue->isEmpty()) {
			/* @var MiddlewareInterface $middleware */
			$middleware = $middlewareQueue->extract();

			return $middleware->process($request, $handler);
		}

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
		throw new NotFoundHttpException;
	}

	private function handleMethodNotAllowed(array $methods)
	{
		throw new MethodNotAllowedHttpException('Allow: ' . implode(', ', $methods));
	}

	/**
	 * @waring Route discovery is handled by the parameter resolver in `super-kernel/di`. If another DI container is
	 *         used, remap the provider of `SuperKernel\HttpServer\Contract\MiddlewareDispatcherInterface`.
	 *
	 * @param Dispatched $dispatched
	 *
	 * @return ResponseInterface
	 */
	private function handleFound(Dispatched $dispatched): ResponseInterface
	{
		$routeData           = $dispatched->handler;
		$parameterDefinition = new ParameterDefinition($routeData->controller, $routeData->action, $dispatched->parameters);
		$arguments           = $this->resolverDispatcher->getResolver($parameterDefinition)->resolve($parameterDefinition);

		$response = call_user_func(
			callback: [
				          $routeData->controller,
				          $routeData->action,
			          ],
			args    : $arguments,
		);

		if ($response instanceof ResponseInterface) {
			return $response;
		}

		return match (true) {
			is_array($response) => $this->response->withBody(new JsonStream($response)),
			default             => $this->response->withBody(new StandardStream($response)),
		};
	}
}