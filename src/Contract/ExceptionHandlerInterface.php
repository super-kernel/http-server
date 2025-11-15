<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Psr\Http\Message\ResponseInterface;
use Throwable;

interface ExceptionHandlerInterface
{
	public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface;

	public function isStopPropagation(): bool;
}