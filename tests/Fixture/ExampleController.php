<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class ExampleController
{
    public function __construct(private InvocationLog $log)
    {
    }

    /**
     * @return array{id: int, name: string, routeAttribute: string}
     */
    public function show(ServerRequestInterface $request, int $id, GreetingService $greeting): array
    {
        $this->log->entries[] = 'controller';

        return [
            'id'             => $id,
            'name'           => $greeting->greet($id),
            'routeAttribute' => $request->getAttribute('id'),
        ];
    }

    public function fail(): never
    {
        throw new RuntimeException('controller failed');
    }

    public function withoutResult(): void
    {
        $this->log->entries[] = 'controller:void';
    }

    /**
     * @return array{name: string, ratio: float, enabled: bool, state: string}
     */
    public function convert(string $name, float $ratio, bool $enabled, RouteState $state): array
    {
        return [
            'name'    => $name,
            'ratio'   => $ratio,
            'enabled' => $enabled,
            'state'   => $state->value,
        ];
    }
}
