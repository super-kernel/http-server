<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
<<<<<<< Updated upstream
use SuperKernel\HttpServer\Attribute\Middleware;
=======
use SuperKernel\HttpServer\Attribute\Middlewares;
>>>>>>> Stashed changes
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernelTest\HttpServer\Middleware\BeforeMiddleware;

#[
	HttpController(prefix: '/index', server: 'http'),
<<<<<<< Updated upstream
	Middleware(middleware: BeforeMiddleware::class),
=======
	Middlewares([
		BeforeMiddleware::class,
	]),
>>>>>>> Stashed changes
]
final readonly class IndexController
{
	public function __construct(private ServerRequestInterface $serverRequest, private ResponseInterface $response)
	{
	}

<<<<<<< Updated upstream
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
=======
	#[RequestMapping(path: 'index', methods: 'get')]
	public function index(): array
	{
		var_dump(
			$this->serverRequest->getServerParams(),
			$this->response->getStatusCode(),
>>>>>>> Stashed changes
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