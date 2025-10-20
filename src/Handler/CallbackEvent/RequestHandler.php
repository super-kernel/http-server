<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Handler\CallbackEvent;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Context\MiddlewareContext;
use SuperKernel\HttpServer\Context\RequestContext;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\Provider\MiddlewareManager;
use SuperKernel\HttpServer\Wrapper\RequestWrapper;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use SuperKernel\Server\Attribute\Event;
use SuperKernel\Server\Enumeration\HttpServerEvent;
use Swoole\Http\Request;
use Swoole\Http\Response;

#[Event(HttpServerEvent::ON_REQUEST)]
final readonly class RequestHandler
{
	public function __construct(
		private MiddlewareManager       $middlewareProvider,
		private RequestHandlerInterface $requestHandler,
	)
	{
	}

	/**
	 * @param Request  $request
	 * @param Response $response
	 *
	 * @return void
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(Request $request, Response $response): void
	{
		$serverRequest = RequestContext::set(new RequestWrapper($request));

		MiddlewareContext::set($this->middlewareProvider->getMiddleware());
		ResponseContext::set(new ResponseWrapper());

		$result = $this->requestHandler->handle($serverRequest);

		$response->status($result->getStatusCode());

		foreach ($result->getHeaders() as $key => $values) {
			$response->setHeader($key, $values);
		}

		$response->end($result->getBody()->getContents());

		RequestContext::delete();
		ResponseContext::delete();
	}
}