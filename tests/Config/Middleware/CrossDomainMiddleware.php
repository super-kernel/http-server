<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Config\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Attribute\Middleware;

#[Middleware(server: 'http')]
final readonly class CrossDomainMiddleware implements MiddlewareInterface
{
	public function __construct(private ResponseInterface $response)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$this->response
			->withHeader('Access-Control-Allow-Origin', '*')
			->withHeader('Access-Control-Allow-Credentials', 'true');

		if ($request->getMethod() == 'OPTIONS') {
			return $this->response;
		}

		return $handler->handle($request);
	}
}