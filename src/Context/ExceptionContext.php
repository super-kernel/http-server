<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Context;

use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Context\Context;
use SuperKernel\HttpServer\Contract\ExceptionHandlerInterface;

final class ExceptionContext
{
	public static function get(): SplPriorityQueue
	{
		$middleware = Context::get(ExceptionHandlerInterface::class);

		if ($middleware instanceof SplPriorityQueue) {
			return $middleware;
		}

		throw new RuntimeException(
			sprintf(
				'Expected instance of %s in Context, but none found.',
				SplPriorityQueue::class,
			),
		);
	}

	public static function has(): bool
	{
		return Context::has(ExceptionHandlerInterface::class);
	}

	public static function set(SplPriorityQueue $exception): SplPriorityQueue
	{
		Context::set(ExceptionHandlerInterface::class, $exception);

		return $exception;
	}
}