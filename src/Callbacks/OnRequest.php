<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Callbacks;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\Attribute\Provider;
use SuperKernel\HttpServer\Context\RequestContext;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\Factory\DispatcherFactory;
use SuperKernel\HttpServer\Factory\ExceptionDispatcherFactory;
use SuperKernel\HttpServer\RequestHandler;
use SuperKernel\HttpServer\ResponseEmitter;
use SuperKernel\HttpServer\Wrapper\RequestWrapper;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use SuperKernel\Server\Contract\Callbacks\OnRequestInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

#[Provider(OnRequestInterface::class)]
final class OnRequest implements OnRequestInterface
{
	private RequestHandlerInterface $requestHandler;

	public function __construct(
		private readonly ResponseEmitter            $responseEmitter,
		private readonly DispatcherFactory          $routeDispatcherFactory,
		private readonly MiddlewareInterface        $middleware,
		private readonly ExceptionDispatcherFactory $exceptionDispatcherFactory,
	)
	{
	}

	/**
	 * @param string $serverName
	 *
	 * @return void
	 */
	public function setServerName(string $serverName): void
	{
		$dispatcher = $this->routeDispatcherFactory->getDispatcher($serverName);

		$this->requestHandler = new RequestHandler(
			$this->middleware,
			$dispatcher,
			$this->exceptionDispatcherFactory->getDispatcher($serverName),
		);
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

	public function __invoke(Request $request, Response $response): void
	{
		//  WebSocket handshake, not entering the transmitter process.
		if ($request->header['upgrade'] ?? '' === 'websocket') {
			return;
		}

		$psr7Request = $this->initRequestAndResponse($request, $response);
		$psr7Response = $this->requestHandler->handle($psr7Request);

		$this->responseEmitter->emit($psr7Response, $response);
	}
}