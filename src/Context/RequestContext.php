<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Context\RequestContext;

use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SuperKernel\Context\Context;

final class RequestContext
{
	public static function get(): RequestInterface
	{
		$request = Context::get(RequestInterface::class);

		if ($request instanceof RequestInterface) {
			return $request;
		}

		throw new RuntimeException(
			sprintf(
				'Expected instance of %s in Context, but none found.',
				RequestInterface::class,
			),
		);
	}

	public static function set(RequestInterface $value): RequestInterface
	{
		Context::set(RequestInterface::class, $value);

		return $value;
	}

	public static function delete(): void
	{
		Context::delete(RequestInterface::class);
	}
}