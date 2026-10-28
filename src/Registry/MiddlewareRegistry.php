<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Registry;

use Psr\Http\Server\MiddlewareInterface;
use SplPriorityQueue;
use SuperKernel\HttpServer\Routing\RouteDefinition;

use function array_map;
use function count;

final readonly class MiddlewareRegistry
{
    /** @var array<non-empty-string, list<MiddlewareInterface>> */
    private array $serverPlans;

    /** @var array<class-string, list<MiddlewareInterface>> */
    private array $defaultRoutePlans;

    /** @var array<non-empty-string, array<class-string, list<MiddlewareInterface>>> */
    private array $routePlans;

    /**
     * @param list<MiddlewareInterface>                          $globalMiddlewares
     * @param array<non-empty-string, list<MiddlewareInterface>> $serverMiddlewares
     * @param array<class-string, list<MiddlewareInterface>>     $routeMiddlewares
     */
    public function __construct(
        private array $globalMiddlewares,
        private array $serverMiddlewares,
        private array $routeMiddlewares,
    )
    {
        $serverPlans = [];
        $routePlans = [];

        foreach ($serverMiddlewares as $serverName => $middlewares) {
            $serverPlans[$serverName] = self::ordered($globalMiddlewares, $middlewares);

            foreach ($routeMiddlewares as $controller => $route) {
                $routePlans[$serverName][$controller] = self::ordered(
                    $globalMiddlewares,
                    $middlewares,
                    $route,
                );
            }
        }

        $defaultRoutePlans = array_map(function ($middlewares) use ($globalMiddlewares) {
            return self::ordered($globalMiddlewares, $middlewares);
        }, $routeMiddlewares);

        $this->serverPlans = $serverPlans;
        $this->defaultRoutePlans = $defaultRoutePlans;
        $this->routePlans = $routePlans;
    }

    /**
     * @return list<MiddlewareInterface>
     */
    public function globalMiddlewares(): array
    {
        return $this->globalMiddlewares;
    }

    /**
     * @param non-empty-string $serverName
     *
     * @return list<MiddlewareInterface>
     */
    public function serverMiddlewares(string $serverName): array
    {
        return $this->serverMiddlewares[$serverName] ?? [];
    }

    /**
     * @return list<MiddlewareInterface>
     */
    public function routeMiddlewares(RouteDefinition $route): array
    {
        return $this->routeMiddlewares[$route->controller] ?? [];
    }

    /**
     * @param non-empty-string $serverName
     *
     * @return list<MiddlewareInterface>
     */
    public function middlewares(string $serverName, ?RouteDefinition $route = null): array
    {
        if (null === $route) {
            return $this->serverPlans[$serverName] ?? $this->globalMiddlewares;
        }

        if (isset($this->routePlans[$serverName][$route->controller])) {
            return $this->routePlans[$serverName][$route->controller];
        }

        if (isset($this->serverPlans[$serverName])) {
            return $this->serverPlans[$serverName];
        }

        return $this->defaultRoutePlans[$route->controller] ?? $this->globalMiddlewares;
    }

    /**
     * @param list<MiddlewareInterface> ...$layers
     *
     * @return list<MiddlewareInterface>
     */
    private static function ordered(array ...$layers): array
    {
        /** @var SplPriorityQueue<MiddlewareInterface, array{int, int}> $queue */
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $priority = count($layers);
        $position = 0;

        foreach ($layers as $middlewares) {
            foreach ($middlewares as $middleware) {
                $queue->insert($middleware, [
                    $priority,
                    -$position++,
                ]);
            }

            --$priority;
        }

        $ordered = [];

        while (!$queue->isEmpty()) {
            $ordered[] = $queue->extract();
        }

        return $ordered;
    }
}
