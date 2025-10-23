<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\CallbackEvent;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Context\RequestContext;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher;
use SuperKernel\HttpServer\Dispatcher\MiddlewareDispatcher;
use SuperKernel\HttpServer\Dispatcher\RequestHandler;
use SuperKernel\HttpServer\ResponseEmitter;
use SuperKernel\HttpServer\Wrapper\RequestWrapper;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

final readonly class OnRequestEvent
{
	private RequestHandlerInterface $requestHandler;

	private string $serverName;

	public function __construct(
		private HttpExceptionDispatcher $httpExceptionDispatcher,
		private MiddlewareDispatcher    $middlewareDispatcher,
		private ResponseEmitter         $responseEmitter,
	)
	{
	}

	public function setServerName(string $serverName): void
	{
		$this->serverName = $serverName;

		$this->middlewareDispatcher->setServerName($serverName);
		$this->requestHandler = new RequestHandler($this->middlewareDispatcher);
	}

	/**
	 * @param Request  $request
	 * @param Response $response
	 *
	 * @return void
	 */
	public function __invoke(Request $request, Response $response): void
	{
		//  WebSocket handshake, not entering the transmitter process.
		if ($request->header['upgrade'] ?? '' === 'websocket') {
			return;
		}

		try {
			$psr7Request  = $this->initRequestAndResponse($request, $response);
			$psr7Request  = $this->middlewareDispatcher->dispatch($psr7Request);
			$psr7Response = $this->requestHandler->handle($psr7Request);
		}
		catch (Throwable $throwable) {
			$psr7Response = $this->httpExceptionDispatcher->dispatcher($this->serverName, $throwable);
		}
		finally {
			$this->responseEmitter->emit($psr7Response, $response);
		}
	}

	/**
	 * @param Request  $request
	 * @param Response $response
	 *
	 * @return ServerRequestInterface
	 */
	private function initRequestAndResponse(Request $request, Response $response): ServerRequestInterface
	{
		RequestContext::set($psr7Request = new RequestWrapper($request));
		ResponseContext::set(new ResponseWrapper()->setSwooleResponse($response));

		return $psr7Request;
	}
}