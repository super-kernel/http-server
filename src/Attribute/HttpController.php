<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HttpController
{
	public function __construct(public ?string $prefix = null, public string $server = 'http')
	{
	}
}