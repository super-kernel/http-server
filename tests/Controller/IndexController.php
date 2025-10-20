<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Enumeration\Method;

#[HttpController(prefix: '/index', server: 'http')]
final readonly class IndexController
{
	public function __construct(private ServerRequestInterface $serverRequest, private ResponseInterface $response)
	{
	}

	#[RequestMapping('index', Method::GET)]
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