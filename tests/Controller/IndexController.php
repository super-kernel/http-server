<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Controller;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\Di\Attribute\Autowired;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Contract\ResponseInterface;
use SuperKernelTest\HttpServer\Middleware\BeforeMiddleware;

#[
	Controller(prefix: '/index', server: 'http'),
	Middlewares([
		BeforeMiddleware::class,
	]),
]
final readonly class IndexController
{
	#[Autowired]
	private ServerRequestInterface $serverRequest;

	public function __construct(private ResponseInterface $response)
	{
	}

	#[RequestMapping(path: 'index', methods: 'get')]
	public function index(): MessageInterface
	{
		return $this->response
			->chunk(function () {
				yield 1;
				yield 2;
				yield 3;
			});
	}
}