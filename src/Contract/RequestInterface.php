<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Psr\Http\Message\ServerRequestInterface;
use Swoole\Http\Request;

interface RequestInterface extends ServerRequestInterface
{
	public function getServerRequest(): ServerRequestInterface;

	public function getSwooleRequest(): Request;
}