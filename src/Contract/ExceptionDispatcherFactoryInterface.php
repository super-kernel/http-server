<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

interface ExceptionDispatcherFactoryInterface
{
	public function getDispatcher(string $serverName): ExceptionDispatcherInterface;
}