<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Middleware;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\MiddlewareInterface;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;

use function is_a;
use function sprintf;

/**
 * Validates a middleware class declared through HTTP Attributes and resolves its PSR-15 instance from the container.
 *
 * @internal
 */
final class DeclaredMiddleware
{
    /**
     * @param string $target the class whose declaration names the middleware
     *
     * @throws InvalidHttpDeclarationException When the class does not implement the PSR-15 middleware interface
     */
    public static function assertImplementation(string $className, string $target): void
    {
        if (!is_a($className, MiddlewareInterface::class, true)) {
            throw new InvalidHttpDeclarationException(sprintf(
                'HTTP middleware %s declared by %s must implement %s.',
                $className,
                $target,
                MiddlewareInterface::class,
            ));
        }
    }

    /**
     * The native return type rejects a container entry that is not PSR-15 middleware.
     *
     * @param class-string<MiddlewareInterface> $className
     *
     * @throws NotFoundExceptionInterface  When the middleware is unavailable in the container
     * @throws ContainerExceptionInterface When the container cannot resolve the middleware
     */
    public static function resolve(string $className, ContainerInterface $container): MiddlewareInterface
    {
        return $container->get($className);
    }
}
