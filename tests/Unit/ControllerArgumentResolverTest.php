<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Routing\ControllerArgumentResolver;
use SuperKernel\HttpServer\Routing\RouteDefinition;
use SuperKernel\HttpServer\Tests\Fixture\GreetingService;
use SuperKernel\HttpServer\Tests\Fixture\NumericRouteState;
use SuperKernel\HttpServer\Tests\Fixture\RouteState;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Throwable;

final class ControllerArgumentResolverTest extends TestCase
{
    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testResolvesHttpAwareControllerArguments(): void
    {
        $controller = new class() {
            public function show(
                ServerRequestInterface $request,
                int                    $id,
                GreetingService        $greeting,
            ): void
            {
            }
        };
        $request = new ServerRequest('GET', '');
        $greeting = new GreetingService();

        $arguments = $this->resolver([
            GreetingService::class => $greeting,
        ])->resolve(
            new RouteDefinition($controller::class, 'show'),
            $request,
            [
                'id' => '42',
            ],
        );

        self::assertSame([
            $request,
            42,
            $greeting,
        ], $arguments);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testConvertsReflectionFailuresToInvalidHttpDeclarations(): void
    {
        $controller = new class() {
            public function show(): void
            {
            }
        };
        $failure = new ReflectionException('missing action');
        $reflector = $this->createStub(ReflectorInterface::class);
        $reflector->method('reflectMethod')->willThrowException($failure);

        try {
            $this->resolver(reflector: $reflector)->resolve(
                new RouteDefinition($controller::class, 'show'),
                new ServerRequest('GET', ''),
                [],
            );
            self::fail('The reflection failure was not converted.');
        } catch (InvalidHttpDeclarationException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAnUnknownBackedEnumRouteValue(): void
    {
        $controller = new class() {
            public function show(RouteState $state): void
            {
            }
        };

        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('cannot be converted');

        $this->resolver()->resolve(
            new RouteDefinition($controller::class, 'show'),
            new ServerRequest('GET', ''),
            [
                'state' => 'unknown',
            ],
        );
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testConvertsAnIntegerBackedEnumRouteValue(): void
    {
        $controller = new class() {
            public function show(NumericRouteState $state): void
            {
            }
        };

        self::assertSame([
            NumericRouteState::Ready,
        ], $this->resolver()->resolve(
            new RouteDefinition($controller::class, 'show'),
            new ServerRequest('GET', ''),
            [
                'state' => '42',
            ],
        ));
    }

    /**
     * @param array<string, object> $entries
     */
    private function resolver(
        array               $entries = [],
        ?ReflectorInterface $reflector = null,
    ): ControllerArgumentResolver
    {
        return new ControllerArgumentResolver(
            new TestContainer($entries),
            $reflector ?? new ReflectorProvider()(),
        );
    }
}
