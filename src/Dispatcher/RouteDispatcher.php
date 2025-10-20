<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use Psr\Http\Message\ResponseInterface;
use SuperKernel\Attribute\Provider;
use Swoole\Http\Request;
use Swoole\Http\Response;

#[
	Provider(Dispatcher::class),
	Provider(Dispatcher::class),
]
final readonly class RouteDispatcher
{
	/**
	 * @param Dispatcher            $dispatcher
	 *
	 * @psalm-param GroupCountBased $dispatcher
	 */
	public function __construct(private Dispatcher $dispatcher)
	{
	}

	public function dispatch(Request $request, Response $response): void
	{
		$httpMethod = $request->getMethod();
		$uri        = $request->server['request_uri'];
		$routeInfo  = $this->dispatcher->dispatch($httpMethod, $uri);

		switch ($routeInfo[0]) {
			case Dispatcher::NOT_FOUND:
				$response->end('404 Not Found.');
				break;
			case Dispatcher::METHOD_NOT_ALLOWED:
				$response->end('405 Method Not Allowed.');
				break;
			case Dispatcher::FOUND:
				/* @var ResponseInterface $responseBody */
				$responseBody = call_user_func($routeInfo[1]);
				$response->end($responseBody->getBody()->getContents());
		}
	}
}