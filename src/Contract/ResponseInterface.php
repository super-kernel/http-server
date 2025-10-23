<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Swoole\Http\Response;

interface ResponseInterface extends PsrResponseInterface
{
	public function getResponse(): PsrResponseInterface;

	public function getSwooleResponse(): Response;

	public function setSwooleResponse(Response $response): ResponseInterface;
}