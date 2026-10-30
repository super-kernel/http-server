<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Middleware\DeclaredMiddleware;
use SuperKernel\HttpServer\Registry\MiddlewareRegistry;

#[FactoryProvider(MiddlewareRegistry::class)]
final readonly class MiddlewareRegistryProvider
{
    /**
     * @throws InvalidHttpDeclarationException When a declared middleware class does not implement PSR-15 middleware
     * @throws NotFoundExceptionInterface      When a declared middleware is unavailable in the container
     * @throws ContainerExceptionInterface     When the container cannot resolve a declared middleware
     */
    public function __invoke(
        AnnotationCollectorInterface $collector,
        ContainerInterface           $container,
    ): MiddlewareRegistry
    {
        $globalMiddlewares = [];
        $serverMiddlewares = [];

        foreach ($collector->getClassesWithAttribute(Middleware::class) as $className => [$metadata]) {
            DeclaredMiddleware::assertImplementation($className, $className);
            $declaration = $metadata->attribute();
            $middleware = DeclaredMiddleware::resolve($className, $container);

            if (null === $declaration->name) {
                $globalMiddlewares[] = $middleware;

                continue;
            }

            $serverMiddlewares[$declaration->name][] = $middleware;
        }

        $routeMiddlewares = [];

        foreach ($collector->getClassesWithAttribute(Controller::class) as $className => $attributes) {
            $declarations = [];

            foreach ($collector->getClassAttributes($className) as $attribute) {
                if (Middlewares::class === $attribute->name()) {
                    /** @var Middlewares $middlewaresDeclaration */
                    $middlewaresDeclaration = $attribute->attribute();
                    $declarations = $middlewaresDeclaration->middlewares;

                    break;
                }
            }

            foreach ($declarations as $middlewareClass) {
                DeclaredMiddleware::assertImplementation($middlewareClass, $className);
                $routeMiddlewares[$className][] = DeclaredMiddleware::resolve($middlewareClass, $container);
            }
        }

        return new MiddlewareRegistry(
            $globalMiddlewares,
            $serverMiddlewares,
            $routeMiddlewares,
        );
    }
}
