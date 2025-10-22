<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

<<<<<<< Updated upstream
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use Psr\Http\Message\ResponseInterface;
<<<<<<< HEAD
use SuperKernel\Attribute\Contract;
=======
>>>>>>> main
use SuperKernel\Attribute\Provider;
use Swoole\Http\Request;
use Swoole\Http\Response;

#[
	Provider(Dispatcher::class),
<<<<<<< HEAD
	Contract(Dispatcher::class),
=======
	Provider(Dispatcher::class),
>>>>>>> main
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
=======
use Psr\Http\Message\ServerRequestInterface;

final class RouteDispatcher
{
	public function dispatch(string $serverName, ServerRequestInterface $request)
>>>>>>> Stashed changes
	{

<<<<<<< Updated upstream
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
=======
>>>>>>> Stashed changes
	}
}