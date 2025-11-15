<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use SuperKernel\HttpServer\ExceptionDispatcher;

interface ExceptionDispatcherFactoryInterface
{
	public function getDispatcher(string $serverName): ExceptionDispatcher;
}