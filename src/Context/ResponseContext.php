<?php

namespace SuperKernel\HttpServer\Context\RequestContext;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SuperKernel\Context\Context;

final class ResponseContext
{
	public static function get(): ResponseInterface
	{
		$request = Context::get(ResponseInterface::class);

		if ($request instanceof ResponseInterface) {
			return $request;
		}

		throw new RuntimeException(
			sprintf(
				'Expected instance of %s in Context, but none found.',
				ResponseInterface::class,
			),
		);
	}

	public static function set(ResponseInterface $value): ResponseInterface
	{
		Context::set(ResponseInterface::class, $value);

		return $value;
	}

	public static function delete(): void
	{
		Context::delete(ResponseInterface::class);
	}
}