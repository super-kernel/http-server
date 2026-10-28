<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Routing;

final readonly class RouteDefinition
{
    /**
     * @param class-string     $controller
     * @param non-empty-string $action
     */
    public function __construct(public string $controller, public string $action)
    {
    }
}
