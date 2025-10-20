<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Handler;

use FastRoute\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
<<<<<<< HEAD
use SuperKernel\Attribute\Contract;
=======
>>>>>>> main
use SuperKernel\Attribute\Provider;
use SuperKernel\HttpServer\Contract\MiddlewareInterface;
use SuperKernel\HttpServer\Message\SwooleStream;
use function json_encode;

#[
<<<<<<< HEAD
	Contract(RequestHandlerInterface::class),
=======
>>>>>>> main
	Provider(RequestHandlerInterface::class),
]
final readonly class RequestHandler implements RequestHandlerInterface
{
	public function __construct(
		private Dispatcher          $dispatcher,
		private MiddlewareInterface $middleware,
		private ResponseInterface   $response,
	)
	{
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		if (!$this->middleware->isEmpty()) {
			/* @var PsrMiddlewareInterface $middleware */
			$middleware = $this->middleware->extract();

			return $middleware->process($request, $this);
		}

		$httpMethod = $request->getMethod();
		$uri        = $request->getUri()->getPath();
		$routeInfo  = $this->dispatcher->dispatch($httpMethod, $uri);

		switch ($routeInfo[0]) {
			case Dispatcher::NOT_FOUND:
				return $this->response->withStatus(404)->withBody(new SwooleStream('Not Found.'));
			case Dispatcher::METHOD_NOT_ALLOWED:
				return $this->response->withStatus(405)->withBody(new SwooleStream('Method Not Allowed.'));
			case Dispatcher::FOUND:
		}

		$result = call_user_func($routeInfo[1]);

		return $this->response->withStatus(200)->withBody(match (true) {
			$result instanceof ResponseInterface => $result->getBody(),
			is_array($result)                    => new SwooleStream(json_encode($result)),
			default                              => new SwooleStream($result),
		});
	}
}