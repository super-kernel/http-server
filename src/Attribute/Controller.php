<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Controller
{
	public function __construct(public string $prefix = '', public string $server = 'http')
	{
	}
}