<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

final readonly class GreetingService
{
    public function greet(int $id): string
    {
        return 'widget-' . $id;
    }
}
