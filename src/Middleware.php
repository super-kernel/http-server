<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use Exception;
use FastRoute\Dispatcher;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use SuperKernel\Attribute\Provider;
use SuperKernel\Di\Definition\MethodDefinition;
use SuperKernel\Di\Resolver\MethodResolver;
use SuperKernel\HttpServer\Exception\MethodNotAllowedHttpException;
use SuperKernel\HttpServer\Exception\NotFoundHttpException;
use SuperKernel\HttpServer\Router\Dispatched;
use SuperKernel\Stream\SwooleStream;
use function implode;
use function is_array;
use function json_encode;
use function sprintf;

#[Provider(MiddlewareInterface::class)]
final readonly class Middleware implements MiddlewareInterface
{
	public function __construct(
		private ContainerInterface $container,
		private ResponseInterface  $response,
		private MethodResolver     $methodResolver,
	)
	{
	}

	/**
	 * @param ServerRequestInterface  $request
	 * @param RequestHandlerInterface $handler
	 *
	 * @return ResponseInterface
	 * @throws ContainerExceptionInterface
	 * @throws Exception
	 * @throws NotFoundExceptionInterface
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
			throw new RuntimeException(
				sprintf('The dispatched object is not a %s object.', Dispatched::class));
		}

		var_dump($dispatched->status);

		$response = match ($dispatched->status) {
			Dispatcher::NOT_FOUND          => $this->handleNotFound(),
			Dispatcher::FOUND              => $this->handleFound($dispatched),
			Dispatcher::METHOD_NOT_ALLOWED => $this->handleMethodNotAllowed($dispatched->parameters),
		};

		var_dump($response, $this->transferToResponse($response));

		return $this->transferToResponse($response);
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
	 * @param Dispatched $dispatched
	 *
	 * @return mixed
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	private function handleFound(Dispatched $dispatched): mixed
	{
		$routeData = $dispatched->handler;

		$controller = $this->container->get($routeData->getController());
		$action = $routeData->getAction();

		$methodDefinition = new MethodDefinition($routeData->getController(), $action, $dispatched->parameters);
		$arguments = $this->methodResolver->resolve($methodDefinition);

		var_dump(
			$controller,
			$action,
		);

		return $controller->{$action}(...$arguments);
	}

	private function transferToResponse(mixed $response): ResponseInterface
	{
		if (is_string($response)) {
			return $this->response
				->withHeader('content-type', 'text/plain')
				->withBody(new SwooleStream($response));
		}

		if (is_array($response)) {
			return $this->response
				->withHeader('content-type', 'application/json')
				->withBody(new SwooleStream(json_encode($response)));
		}

		if ($this->response->hasHeader('content-type')) {
			return $this->response->withBody(new SwooleStream((string)$response));
		}

		return $this->response->withHeader('content-type', 'text/plain')->withBody(new SwooleStream((string)$response));
	}
}