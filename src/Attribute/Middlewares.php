<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Middlewares
{
	/**
	 * @var array<Middleware> $middlewares
	 */
	public array $middlewares = [];

	public function __construct(array $middlewares)
	{
		foreach ($middlewares as $middleware => $priority) {
			if (is_int($middleware)) {
				[$middleware, $priority] = [$priority, 0];
			}

			$this->middlewares[] = new Middleware((string)$middleware, (int)$priority);
		}
	}
}