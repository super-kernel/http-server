<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use SuperKernel\Contract\ReflectorInterface;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Routing\ControllerArgumentResolver;
use SuperKernel\HttpServer\Routing\RouteDefinition;
use SuperKernel\HttpServer\Tests\Fixture\GreetingService;
use SuperKernel\HttpServer\Tests\Fixture\NumericRouteState;
use SuperKernel\HttpServer\Tests\Fixture\RouteState;
use SuperKernel\HttpServer\Tests\Fixture\RouteValueController;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use SuperKernel\HttpServer\Tests\Fixture\UnitRouteState;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Throwable;

use function sprintf;

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
    #[DataProvider('convertibleRouteValues')]
    public function testConvertsARouteValueToTheParameterType(string $action, string $value, mixed $expected): void
    {
        self::assertSame([
            $expected,
        ], $this->resolver()->resolve(
            new RouteDefinition(RouteValueController::class, $action),
            new ServerRequest('GET', ''),
            [
                'value' => $value,
            ],
        ));
    }

    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function convertibleRouteValues(): iterable
    {
        yield 'string' => [
            'string',
            'abc',
            'abc',
        ];
        yield 'mixed' => [
            'mixed',
            'abc',
            'abc',
        ];
        yield 'int' => [
            'int',
            '42',
            42,
        ];
        yield 'negative int' => [
            'int',
            '-7',
            -7,
        ];
        yield 'nullable int' => [
            'nullableInt',
            '5',
            5,
        ];
        yield 'float' => [
            'float',
            '1.5',
            1.5,
        ];
        yield 'integral float' => [
            'float',
            '2',
            2.0,
        ];
        yield 'bool true' => [
            'bool',
            'TRUE',
            true,
        ];
        yield 'bool off' => [
            'bool',
            'off',
            false,
        ];
        yield 'bool zero' => [
            'bool',
            '0',
            false,
        ];
        yield 'string-backed enum' => [
            'stringEnum',
            'enabled',
            RouteState::Enabled,
        ];
        yield 'int-backed enum' => [
            'intEnum',
            '42',
            NumericRouteState::Ready,
        ];
        yield 'union passes the string' => [
            'union',
            '7',
            '7',
        ];
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('unconvertibleRouteValues')]
    public function testAnswersBadRequestForAValueTheParameterTypeRejects(string $action, string $value): void
    {
        try {
            $this->resolver()->resolve(
                new RouteDefinition(RouteValueController::class, $action),
                new ServerRequest('GET', ''),
                [
                    'value' => $value,
                ],
            );
            self::fail('The route value was converted.');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame('Bad Request', $exception->getMessage());
            self::assertSame([], $exception->headers);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unconvertibleRouteValues(): iterable
    {
        yield 'int from text' => [
            'int',
            'abc',
        ];
        yield 'int from a fraction' => [
            'int',
            '1.5',
        ];
        yield 'nullable int from text' => [
            'nullableInt',
            'abc',
        ];
        yield 'float from text' => [
            'float',
            '1.5x',
        ];
        yield 'bool from text' => [
            'bool',
            'maybe',
        ];
        yield 'unknown string-backed case' => [
            'stringEnum',
            'unknown',
        ];
        yield 'unknown int-backed case' => [
            'intEnum',
            '7',
        ];
        yield 'int-backed case from text' => [
            'intEnum',
            'ready',
        ];
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('unsupportedRouteParameters')]
    public function testRejectsAParameterTypeThatCannotReceiveARouteValue(string $action, string $type): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessage(sprintf(
            'Route parameter $value of %s::%s() has type %s, which cannot receive a route value.',
            RouteValueController::class,
            $action,
            $type,
        ));

        $this->resolver()->resolve(
            new RouteDefinition(RouteValueController::class, $action),
            new ServerRequest('GET', ''),
            [
                'value' => 'ready',
            ],
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedRouteParameters(): iterable
    {
        yield 'class' => [
            'object',
            GreetingService::class,
        ];
        yield 'unit enum' => [
            'unitEnum',
            UnitRouteState::class,
        ];
        yield 'array' => [
            'array',
            'array',
        ];
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
