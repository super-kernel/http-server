<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use Attribute;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler as BaseExceptionHandler;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\HttpExceptionHandler;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Constants\HttpMethod;
use Throwable;
use TypeError;

final class HttpAttributeContractTest extends TestCase
{
    public function testRequestMappingDeclaresAllStandardHttpMethods(): void
    {
        $methods = [];

        foreach (HttpMethod::cases() as $method) {
            $methods[$method->name] = $method->value;
        }

        self::assertSame([
            'Get'     => 'GET',
            'Head'    => 'HEAD',
            'Post'    => 'POST',
            'Put'     => 'PUT',
            'Patch'   => 'PATCH',
            'Delete'  => 'DELETE',
            'Connect' => 'CONNECT',
            'Options' => 'OPTIONS',
            'Trace'   => 'TRACE',
        ], $methods);
    }

    public function testRequestMappingUsesDeclaredHttpMethodConstants(): void
    {
        $mapping = new RequestMapping('/widgets', HttpMethod::Post);

        self::assertSame('/widgets', $mapping->path);
        self::assertSame(HttpMethod::Post, $mapping->method);
        self::assertSame(HttpMethod::Get, new RequestMapping('/widgets')->method);
        self::assertSame('', new Controller()->prefix);
        self::assertSame('http', new Controller()->server);
        self::assertSame('admin', new Controller(server: 'admin')->server);
        self::assertNull(new Middleware()->name);
        self::assertSame('http', new Middleware(name: 'http')->name);
        self::assertSame([
            self::class,
        ], new Middlewares([
            self::class,
        ])->middlewares);
    }

    /**
     * The string argument deliberately fails the native HttpMethod parameter type; the construction runs only to throw.
     *
     * @noinspection PhpParamsInspection, PhpExpressionResultUnusedInspection
     */
    public function testRequestMappingRejectsAnUnsupportedHttpMethod(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains(
            'Argument #2 ($method) must be of type ' . HttpMethod::class . ', string given',
        );

        new RequestMapping('/widgets', 'BREW');
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testHttpExceptionHandlerDerivesFromTheSharedClassAttribute(): void
    {
        $reflection = new ReflectionClass(HttpExceptionHandler::class);
        $native = $reflection->getAttributes(Attribute::class, ReflectionAttribute::IS_INSTANCEOF);
        $constructor = $reflection->getConstructor();
        $default = new HttpExceptionHandler();
        $named = new HttpExceptionHandler(name: 'admin');

        self::assertTrue($reflection->isSubclassOf(BaseExceptionHandler::class));
        self::assertCount(1, $native);
        self::assertSame(Attribute::TARGET_CLASS, $native[0]->newInstance()->flags);
        self::assertNotNull($constructor);
        self::assertCount(3, $constructor->getParameters());
        self::assertSame('name', $constructor->getParameters()[0]->getName());
        self::assertSame('string', (string) $constructor->getParameters()[0]->getType());
        self::assertSame('http', $constructor->getParameters()[0]->getDefaultValue());
        self::assertSame('priority', $constructor->getParameters()[1]->getName());
        self::assertSame(0, $constructor->getParameters()[1]->getDefaultValue());
        self::assertSame('exception', $constructor->getParameters()[2]->getName());
        self::assertSame(Throwable::class, $constructor->getParameters()[2]->getDefaultValue());
        self::assertSame(
            RuntimeException::class,
            new HttpExceptionHandler(exception: RuntimeException::class)->exception,
        );
        self::assertSame('http', $default->name);
        self::assertSame('admin', $named->name);
        self::assertSame(Throwable::class, $default->exception);
        self::assertSame(10, new HttpExceptionHandler(priority: 10)->priority);
    }

    public function testHttpExceptionHandlerRejectsAnEmptyServerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('exception-handler service name must be non-empty');

        new HttpExceptionHandler(name: '');
    }

    public function testControllerRejectsAnEmptyServerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('server name must be non-empty');

        new Controller(server: '');
    }

    public function testMiddlewareRejectsAnEmptyOptionalServerName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('server name must be non-empty');

        new Middleware('');
    }

    public function testMiddlewaresRejectsAnInvalidClassName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('non-empty class names without NUL bytes');

        new Middlewares([
            "Acme\\Middleware\0Invalid",
        ]);
    }
}
