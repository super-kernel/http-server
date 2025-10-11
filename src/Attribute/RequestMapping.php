<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;
use SuperKernel\HttpServer\Enumeration\Method;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequestMapping
{
	/**
	 * @param string               $path
	 * @param array<Method>|Method $methods
	 */
	public function __construct(public string $path, public array|Method $methods = [])
	{
	}
}