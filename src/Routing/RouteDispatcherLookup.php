<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Routing;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * Resolves the found-route dispatcher from the container when a request matched a route.
 *
 * @internal
 */
final class RouteDispatcherLookup
{
    /** @throws ContainerExceptionInterface When the route dispatcher cannot be resolved */
    public static function resolve(ContainerInterface $container): RouteDispatcher
    {
        return $container->get(RouteDispatcher::class);
    }
}
