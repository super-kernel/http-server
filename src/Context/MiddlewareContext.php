<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Context;

use RuntimeException;
use SuperKernel\Context\Context;
use SuperKernel\HttpServer\Contract\MiddlewareInterface;

final class MiddlewareContext
{
	public static function get(): MiddlewareInterface
	{
		$middleware = clone Context::get(MiddlewareInterface::class);

		if ($middleware instanceof MiddlewareInterface) {
			return $middleware;
		}

		throw new RuntimeException(
			sprintf(
				'Expected instance of %s in Context, but none found.',
				MiddlewareInterface::class,
			),
		);
	}

	public static function set(MiddlewareInterface $middleware): MiddlewareInterface
	{
		Context::set(MiddlewareInterface::class, $middleware);

		return $middleware;
	}
}