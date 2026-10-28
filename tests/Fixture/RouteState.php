<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

enum RouteState: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
}
