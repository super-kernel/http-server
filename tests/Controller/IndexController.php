<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer;

use Laminas\Diactoros\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Enumeration\Method;

#[HttpController(prefix: '/index', server: 'http')]
final readonly class IndexController
{
	/**
	 * @param ServerRequestInterface $request
	 * @param Response               $response
	 */
	public function __construct(private RequestInterface $request, private ResponseInterface $response)
	{
	}

	#[RequestMapping('index', Method::GET)]
	public function index(): ResponseInterface
	{
		var_dump('Handle for ' . __CLASS__ . '::' . __FUNCTION__);

		var_dump($this->request->getQueryParams());

		return $this->response->withBody(
			new Response\JsonResponse($this->request->getQueryParams())->getBody(),
		);

	}
}