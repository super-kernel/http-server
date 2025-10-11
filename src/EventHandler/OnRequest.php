<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\EventHandler;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SuperKernel\Context\Context;
use SuperKernel\HttpServer\Context\RequestContext\RequestContext;
use SuperKernel\HttpServer\Context\RequestContext\ResponseContext;
use SuperKernel\HttpServer\Factory\RouteDispatcher;
use SuperKernel\HttpServer\Provider\RequestWrapper;
use SuperKernel\HttpServer\Provider\ResponseWrapper;
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