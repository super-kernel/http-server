<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;
use SuperKernel\HttpServer\Constants\HttpMethod;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class RequestMapping
{
    public function __construct(public string $path, public HttpMethod $method = HttpMethod::Get)
    {
    }
}
