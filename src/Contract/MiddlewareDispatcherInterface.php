<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

interface MiddlewareDispatcherInterface extends MiddlewareInterface
{
	public function dispatch(ServerRequestInterface $request): ServerRequestInterface;
}