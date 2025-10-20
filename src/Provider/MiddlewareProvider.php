<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use SuperKernel\Attribute\Provider;
use SuperKernel\HttpServer\Context\MiddlewareContext;
use SuperKernel\HttpServer\Contract\MiddlewareInterface;

#[
	Provider(MiddlewareInterface::class),
]
final class MiddlewareProvider implements MiddlewareInterface
{
	public function current(): mixed
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function next(): void
	{
		$this->__call(__FUNCTION__, func_get_args());
	}

	public function key(): mixed
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function valid(): bool
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function rewind(): void
	{
		$this->__call(__FUNCTION__, func_get_args());
	}

	public function count(): int
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function __call(string $name, array $arguments): mixed
	{
		return call_user_func([
			                      MiddlewareContext::get(),
			                      $name,
		                      ], ...$arguments);
	}
}