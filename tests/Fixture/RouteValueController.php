<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

final readonly class RouteValueController
{
    public function string(string $value): void
    {
    }

    public function mixed(mixed $value): void
    {
    }

    public function int(int $value): void
    {
    }

    public function nullableInt(?int $value): void
    {
    }

    public function float(float $value): void
    {
    }

    public function bool(bool $value): void
    {
    }

    public function stringEnum(RouteState $value): void
    {
    }

    public function intEnum(NumericRouteState $value): void
    {
    }

    public function union(int|string $value): void
    {
    }

    public function object(GreetingService $value): void
    {
    }

    public function unitEnum(UnitRouteState $value): void
    {
    }

    public function array(array $value): void
    {
    }
}
