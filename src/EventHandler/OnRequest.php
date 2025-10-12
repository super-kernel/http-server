<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\EventHandler;

use SuperKernel\HttpServer\Context\RequestContext\RequestContext;
use SuperKernel\HttpServer\Context\RequestContext\ResponseContext;
use SuperKernel\HttpServer\Factory\RouteDispatcher;
use SuperKernel\HttpServer\Wrapper\RequestWrapper;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use SuperKernel\Server\Attribute\Event;
use SuperKernel\Server\Enumeration\HttpServerEvent;
use Swoole\Http\Request;
use Swoole\Http\Response;

#[Event(HttpServerEvent::ON_REQUEST)]
final readonly class OnRequest
{
	public function __construct(private RouteDispatcher $routeDispatcher)
	{
	}

	public function __invoke(Request $request, Response $response): void
	{
		RequestContext::set(new RequestWrapper($request));
		ResponseContext::set(new ResponseWrapper());

		$this->routeDispatcher->dispatch(...func_get_args());

		RequestContext::delete();
		ResponseContext::delete();
	}
}