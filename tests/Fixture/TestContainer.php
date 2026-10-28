<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use Psr\Container\ContainerInterface;
use RuntimeException;

use function array_key_exists;
use function sprintf;

final class TestContainer implements ContainerInterface
{
    /**
     * @param array<string, object> $entries
     */
    public function __construct(private array $entries)
    {
    }

    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new RuntimeException(sprintf('Test container entry %s is missing.', $id));
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function set(string $id, object $entry): void
    {
        $this->entries[$id] = $entry;
    }
}
