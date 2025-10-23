<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Psr\Http\Message\ResponseInterface;
use Throwable;

interface ExceptionDispatcherInterface
{
	public function handle(Throwable $throwable, ExceptionDispatcherInterface $dispatcher): ResponseInterface;
}