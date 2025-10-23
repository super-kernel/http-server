<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Message\SwooleStream;
use SuperKernelTest\HttpServer\Middleware\BeforeMiddleware;
use function json_encode;

#[
	HttpController(prefix: '/index', server: 'http'),
	Middlewares([
		BeforeMiddleware::class,
	]),
]
final readonly class IndexController
{
	public function __construct(private ServerRequestInterface $serverRequest, private ResponseInterface $response)
	{
	}

	#[RequestMapping(path: 'index/{name}', methods: 'post')]
	public function index(string $name): ResponseInterface
	{
		return $this->response
			->withBody(
				new SwooleStream(
					json_encode(
						[
							'code'    => 200,
							'message' => 'OK',
							'data'    => [
								'type'  => $name,
								'Hello' => 'World',
							],
						],
					),
				),
			);
	}
}