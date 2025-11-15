<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\CallbackEvent;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Context\RequestContext;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\ResponseEmitter;
use SuperKernel\HttpServer\Wrapper\RequestWrapper;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use Swoole\Http\Request;
use Swoole\Http\Response;

final readonly class OnRequestEvent
{
	private ResponseEmitter $responseEmitter;

	public function __construct(private RequestHandlerInterface $requestHandler)
	{
		$this->responseEmitter = new ResponseEmitter();
	}

	/**
	 * @param Request  $request
	 * @param Response $response
	 *
	 * @return void
	 */
	public function handle(Request $request, Response $response): void
	{
		//  WebSocket handshake, not entering the transmitter process.
		if ($request->header['upgrade'] ?? '' === 'websocket') {
			return;
		}

		$psr7Request  = $this->initRequestAndResponse($request, $response);
		$psr7Response = $this->requestHandler->handle($psr7Request);

		$this->responseEmitter->emit($psr7Response, $response);
	}

	/**
	 * @param Request  $request
	 * @param Response $response
	 *
	 * @return ServerRequestInterface
	 */
	private
	function initRequestAndResponse(Request $request, Response $response): ServerRequestInterface
	{
		RequestContext::set($psr7Request = new RequestWrapper($request));
		ResponseContext::set(new ResponseWrapper()->setSwooleResponse($response));

		return $psr7Request;
	}
}