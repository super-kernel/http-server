<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Registry;

use Psr\Http\Server\MiddlewareInterface;
use SplPriorityQueue;

use function count;

/**
 * Flattens fixed middleware layers into one plan with a stable priority queue.
 *
 * @internal
 */
final class MiddlewarePlanCompiler
{
    /**
     * Earlier layers run first, and declaration order is preserved inside each layer.
     *
     * @param list<MiddlewareInterface> ...$layers
     *
     * @return list<MiddlewareInterface>
     */
    public static function compile(array ...$layers): array
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
