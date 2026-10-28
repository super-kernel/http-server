<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Middleware;

use Psr\Http\Server\MiddlewareInterface;

/**
 * Immutable request-local position in a compiled middleware plan.
 *
 * @internal
 */
final readonly class MiddlewareCursor
{
    /**
     * @param non-empty-list<MiddlewareInterface> $plan
     * @param non-negative-int                    $index
     */
    public function __construct(private array $plan, private int $index = 0)
    {
    }

    public function current(): ?MiddlewareInterface
    {
        $middleware = $this->plan[$this->index] ?? null;

        return $middleware instanceof MiddlewareInterface ? $middleware : null;
    }

    public function advance(): self
    {
        return new self($this->plan, $this->index + 1);
    }
}
