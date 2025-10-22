<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\CallbackEvent;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Context\RequestContext;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\Dispatcher\MiddlewareDispatcher;
use SuperKernel\HttpServer\Dispatcher\RequestHandler;
use SuperKernel\HttpServer\Message\SwooleStream;
use SuperKernel\HttpServer\Wrapper\RequestWrapper;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

final readonly class OnRequestEvent
{
	private RequestHandlerInterface $requestHandler;

	public function __construct(private MiddlewareDispatcher $middlewareDispatcher)
	{
	}

	public function setServerName(string $serverName): void
	{
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
		try {
			[
				$psr7Request,
				$psr7Response,
			] = $this->initRequestAndResponse($request, $response);

			$psr7Request = $this->middlewareDispatcher->dispatch($psr7Request);

			$psr7Response = $this->requestHandler->handle($psr7Request);
		}
		catch (Throwable $throwable) {
			$psr7Response = new ResponseWrapper()->withStatus(400)->withBody(new SwooleStream('Not found.'));
		}
		finally {
			foreach ($psr7Response->getHeaders() as $key => $value) {
				$response->header($key, $value);
			}

			$response->status($psr7Response->getStatusCode(), $psr7Response->getReasonPhrase());

			$response->end($psr7Response->getBody()->getContents());
		}
	}

	/**
	 * @param Request  $request
	 * @param Response $response
	 *
	 * @return array{ServerRequestInterface, ResponseInterface}
	 */
	private function initRequestAndResponse(Request $request, Response $response): array
	{
		$psr7Request  = RequestContext::set(new RequestWrapper($request));
		$psr7Response = ResponseContext::set(new ResponseWrapper());

		return [
			$psr7Request,
			$psr7Response,
		];
	}
}