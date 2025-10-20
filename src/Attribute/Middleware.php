<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Middleware
{
	public function __construct(public string $middleware, public string $server = '', public int $priority = 0)
	{
	}
}