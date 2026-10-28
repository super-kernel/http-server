<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\MiddlewareInterface;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AttributeMetadataInterface;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Registry\MiddlewareRegistry;

use function is_a;
use function sprintf;

#[FactoryProvider(MiddlewareRegistry::class)]
final readonly class MiddlewareRegistryProvider
{
    /**
     * @throws NotFoundExceptionInterface  When a declared middleware is unavailable in the container
     * @throws ContainerExceptionInterface When the container cannot resolve a declared middleware
     */
    public function __invoke(
        AnnotationCollectorInterface $collector,
        ContainerInterface           $container,
    ): MiddlewareRegistry
    {
        [$globalMiddlewares, $serverMiddlewares] = self::serverMiddlewares($collector, $container);

        return new MiddlewareRegistry(
            $globalMiddlewares,
            $serverMiddlewares,
            self::routeMiddlewares($collector, $container),
        );
    }

    /**
     * @return array{
     *     list<MiddlewareInterface>,
     *     array<non-empty-string, list<MiddlewareInterface>>
     * }
     *
     * @throws NotFoundExceptionInterface  When a declared middleware is unavailable in the container
     * @throws ContainerExceptionInterface When the container cannot resolve a declared middleware
     */
    private static function serverMiddlewares(
        AnnotationCollectorInterface $collector,
        ContainerInterface           $container,
    ): array
    {
        $globalMiddlewares = [];
        $serverMiddlewares = [];

        foreach ($collector->getClassesWithAttribute(Middleware::class) as $className => [$metadata]) {
            self::assertMiddleware($className, $className);
            $declaration = $metadata->attribute();
            $middleware = self::resolve($className, $container);

            if (null === $declaration->name) {
                $globalMiddlewares[] = $middleware;

                continue;
            }

            $serverMiddlewares[$declaration->name][] = $middleware;
        }

        return [
            $globalMiddlewares,
            $serverMiddlewares,
        ];
    }

    /**
     * @return array<class-string, list<MiddlewareInterface>>
     *
     * @throws NotFoundExceptionInterface  When a declared middleware is unavailable in the container
     * @throws ContainerExceptionInterface When the container cannot resolve a declared middleware
     */
    private static function routeMiddlewares(
        AnnotationCollectorInterface $collector,
        ContainerInterface           $container,
    ): array
    {
        $middlewares = [];

        foreach ($collector->getClassesWithAttribute(Controller::class) as $className => $attributes) {
            $declarations = self::declaredMiddlewares($collector->getClassAttributes($className));

            foreach ($declarations as $middleware) {
                self::assertMiddleware($middleware, $className);
                $middlewares[$className][] = self::resolve($middleware, $container);
            }
        }

        return $middlewares;
    }

    /**
     * @param list<AttributeMetadataInterface<object>> $attributes
     *
     * @return list<class-string>
     */
    private static function declaredMiddlewares(array $attributes): array
    {
        foreach ($attributes as $attribute) {
            if (Middlewares::class === $attribute->name()) {
                /** @var Middlewares $declaration */
                $declaration = $attribute->attribute();

                return $declaration->middlewares;
            }
        }

        return [];
    }

    private static function assertMiddleware(string $className, string $target): void
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
     * @param class-string<MiddlewareInterface> $className
     *
     * @throws NotFoundExceptionInterface  When the middleware is unavailable in the container
     * @throws ContainerExceptionInterface When the container cannot resolve the middleware
     */
    private static function resolve(string $className, ContainerInterface $container): MiddlewareInterface
    {
        return $container->get($className);
    }
}
