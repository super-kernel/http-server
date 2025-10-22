<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Context\MiddlewareContext;

final readonly class RequestHandler implements RequestHandlerInterface
{
	public function __construct(private MiddlewareInterface $middleware)
	{
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		if (MiddlewareContext::has() && !MiddlewareContext::get()->isEmpty()) {
			$middleware = MiddlewareContext::get()->extract();

			return $middleware->process($request, $this);
		}

		return $this->middleware->process($request, $this);
	}
}