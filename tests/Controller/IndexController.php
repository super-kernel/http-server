<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Controller;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\Attribute\Autowired;
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
	public function index(): string
	{
		var_dump(12121212);

		return '123';
	}
}