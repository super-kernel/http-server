<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Context;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use SuperKernel\Context\Context;

final class RequestContext
{
	public static function get(): ServerRequestInterface
	{
		$request = Context::get(ServerRequestInterface::class);

		if ($request instanceof ServerRequestInterface) {
			return $request;
		}

		throw new RuntimeException(
			sprintf(
				'Expected instance of %s in Context, but none found.',
				ServerRequestInterface::class,
			),
		);
	}

	public static function set(ServerRequestInterface $serverRequest): ServerRequestInterface
	{
		Context::set(ServerRequestInterface::class, $serverRequest);

		return $serverRequest;
<<<<<<< Updated upstream
	}

	public static function delete(): void
	{
		Context::delete(ServerRequestInterface::class);
=======
>>>>>>> Stashed changes
	}
}