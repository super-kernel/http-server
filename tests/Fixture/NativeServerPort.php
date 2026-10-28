<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use Closure;
use Swoole\Server\Port;

/**
 * Exercises native contracts without opening sockets or starting a server.
 */
final class NativeServerPort extends Port
{
    /** @var array<string, Closure> */
    private array $callbacks = [];

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct(private readonly bool $registrationResult = true)
    {
    }

    public function on(string $event_name, callable $callback): bool
    {
        if (!$this->registrationResult) {
            return false;
        }

        $this->callbacks[$event_name] = $callback(...);

        return true;
    }

    /**
     * Matches the Swoole 6.2 runtime; ide-helper declares a broader callable return type.
     *
     * @noinspection PhpHierarchyChecksInspection
     */
    public function getCallback(string $event_name): ?Closure
    {
        return $this->callbacks[$event_name] ?? null;
    }

    public function callback(string $event_name): ?Closure
    {
        return $this->getCallback($event_name);
    }
}
