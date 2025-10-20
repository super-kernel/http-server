<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernelTest\HttpServer\Middleware\BeforeMiddleware;

#[
	HttpController(prefix: '/index', server: 'http'),
	Middleware(middleware: BeforeMiddleware::class),
]
final readonly class IndexController
{
	public function __construct(private ServerRequestInterface $serverRequest, private ResponseInterface $response)
	{
	}

<<<<<<< HEAD
	#[RequestMapping('index', Method::GET)]
=======
	#[RequestMapping(path: 'index', methods: 'get')]
>>>>>>> main
	public function index(): array
	{
		var_dump(
			$this->serverRequest,
			$this->response,
		);

		return [
			'code'    => 200,
			'message' => 'OK',
			'data'    => [
				'Hello' => 'World',
			],
		];
	}
}