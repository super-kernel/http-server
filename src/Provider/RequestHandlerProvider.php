<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\Attribute\Contract;
use SuperKernel\Attribute\Provider;

#[
	Contract(RequestHandlerInterface::class),
	Provider(RequestHandlerInterface::class),
]
final class RequestHandlerProvider
{

	public function __construct()
	{
	}

	public function __invoke(): RequestHandlerInterface
	{
	}
}