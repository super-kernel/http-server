<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use FastRoute\Dispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Routing\Dispatched;
use SuperKernel\HttpServer\Routing\RouteDefinition;

use function array_map;

final class DispatchedTest extends TestCase
{
    public function testIsARouteOnlyReadonlyValue(): void
    {
        $reflection = new ReflectionClass(Dispatched::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertSame([
            'status',
            'handler',
            'variables',
            'allowedMethods',
        ], array_map(
            static fn($property): string => $property->getName(),
            $reflection->getProperties(),
        ));
        self::assertFalse($reflection->hasMethod('nextMiddleware'));
        self::assertFalse($reflection->hasMethod('hasMiddleware'));
    }

    public function testProvidesTheFoundRouteHandler(): void
    {
        $handler = new RouteDefinition(self::class, __FUNCTION__);
        $dispatched = new Dispatched(
            Dispatcher::FOUND,
            $handler,
        );

        self::assertSame($handler, $dispatched->handler());
    }

    public function testRequiresAHandlerForAFoundRoute(): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('must provide its route handler');

        new Dispatched(
            Dispatcher::FOUND,
        );
    }

    public function testRejectsAHandlerForAnUnmatchedRoute(): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('Only a found HTTP route');

        new Dispatched(
            Dispatcher::NOT_FOUND,
            handler: new RouteDefinition(self::class, __FUNCTION__),
        );
    }
}
