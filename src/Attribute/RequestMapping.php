<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequestMapping
{
	/**
	 * @param string $path
	 * @param string $methods
	 */
	public function __construct(public string $path, public string $methods)
	{
	}
}