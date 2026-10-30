<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionFunction;
use SuperKernel\Attribute\Listener;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\ContainerInterface;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\Di\Resolver\MethodResolver;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerProvider;
use SuperKernel\HttpServer\Callbacks\OnRequestCallback;
use SuperKernel\HttpServer\Exception\RequestCallbackRegistrationException;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use SuperKernel\HttpServer\Listener\BeforeServerStartListener;
use SuperKernel\HttpServer\Request\SwooleRequestAdapter;
use SuperKernel\HttpServer\Response\SwooleResponseEmitter;
use SuperKernel\HttpServer\Tests\Fixture\NativeServer;
use SuperKernel\HttpServer\Tests\Fixture\NativeServerPort;
use SuperKernel\HttpServer\Tests\Fixture\NullExceptionDispatcher;
use SuperKernel\HttpServer\Tests\Fixture\NullStdoutLogger;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use SuperKernel\Server\Config\SwooleServerConfigInterface;
use SuperKernel\Server\Constants\Protocol;
use SuperKernel\Server\Event\AfterServerCreated;
use Swoole\Constant;
use Swoole\Coroutine\Server as CoroutineServer;
use Throwable;

/**
 * @psalm-import-type ListenerConfiguration from SwooleServerConfigInterface
 */
final class BeforeServerStartListenerTest extends TestCase
{
    /** @throws Throwable When fixture setup or a supplied test callback fails */
    protected function setUp(): void
    {
        parent::setUp();

        $exceptionDispatcher = new NullExceptionDispatcher();
        $httpExceptionDispatcherFactory = new HttpExceptionDispatcherFactory(
            new ExceptionHandlerProvider(new TestAnnotationCollector(), new TestContainer([])),
            $exceptionDispatcher,
        );
        $container = new TestContainer([
            RequestHandlerInterface::class        => $this->createStub(RequestHandlerInterface::class),
            SwooleRequestAdapter::class           => new SwooleRequestAdapter(),
            SwooleResponseEmitter::class          => new SwooleResponseEmitter(new NullStdoutLogger()),
            HttpExceptionDispatcherFactory::class => $httpExceptionDispatcherFactory,
        ]);
        // make() and MethodResolver deliberately retain the SuperKernel extension contract.
        $diContainer = new readonly class($container) implements ContainerInterface {
            public function __construct(private TestContainer $entries)
            {
            }

            public function get(string $id): object
            {
                return $this->entries->get($id);
            }

            public function has(string $id): bool
            {
                return $this->entries->has($id);
            }
        };
        $container->set(MethodResolver::class, new MethodResolver($diContainer));
        ApplicationContext::setContainer($diContainer);
    }

    public function testDeclaresTheSharedListenerContract(): void
    {
        $reflection = new ReflectionClass(BeforeServerStartListener::class);
        $attributes = $reflection->getAttributes(Listener::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->implementsInterface(ListenerInterface::class));
        self::assertCount(1, $attributes);
        self::assertSame(0, $attributes[0]->newInstance()->priority);
        self::assertNull($constructor);
        self::assertSame(
            [
                AfterServerCreated::class,
            ],
            new BeforeServerStartListener()->listen(),
        );
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testClaimsRequestCallbackOnTheMainNativeServer(): void
    {
        $server = new NativeServer();
        $listener = new BeforeServerStartListener();

        $listener->process($this->event($server));

        $callback = $server->callback(Constant::EVENT_REQUEST);

        self::assertInstanceOf(Closure::class, $callback);
        self::assertInstanceOf(OnRequestCallback::class, new ReflectionFunction($callback)->getClosureThis());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testClaimsRequestCallbackOnAnAdditionalNativePort(): void
    {
        $server = new NativeServerPort();
        $listener = new BeforeServerStartListener();

        $listener->process($this->event($server));

        $callback = $server->callback(Constant::EVENT_REQUEST);

        self::assertInstanceOf(Closure::class, $callback);
        self::assertInstanceOf(OnRequestCallback::class, new ReflectionFunction($callback)->getClosureThis());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCreatesANewCallbackForEachEligibleService(): void
    {
        $main = new NativeServer();
        $port = new NativeServerPort();
        $listener = new BeforeServerStartListener();

        $listener->process($this->event($main));
        $listener->process(new AfterServerCreated(
            $port,
            [
                'name'     => 'admin',
                'host'     => '127.0.0.1',
                'port'     => 9502,
                'protocol' => Protocol::Http,
            ],
        ));

        $mainCallback = $main->callback(Constant::EVENT_REQUEST);
        $portCallback = $port->callback(Constant::EVENT_REQUEST);

        self::assertInstanceOf(Closure::class, $mainCallback);
        self::assertInstanceOf(Closure::class, $portCallback);
        $mainTarget = new ReflectionFunction($mainCallback)->getClosureThis();
        $portTarget = new ReflectionFunction($portCallback)->getClosureThis();
        self::assertInstanceOf(OnRequestCallback::class, $mainTarget);
        self::assertInstanceOf(OnRequestCallback::class, $portTarget);
        self::assertNotSame(
            $mainTarget,
            $portTarget,
        );
    }

    public function testLeavesAServiceUnsupportedByHttpServerUnchanged(): void
    {
        $server = new class() extends CoroutineServer {
            /**
             * Keeps the unsupported-service fixture free of native sockets.
             *
             * @noinspection PhpMissingParentConstructorInspection
             */
            public function __construct()
            {
            }
        };
        $listener = new BeforeServerStartListener();

        $listener->process($this->event($server));

        $this->addToAssertionCount(1);
    }

    public function testLeavesARawNativeServiceUnchanged(): void
    {
        $server = new NativeServer();
        $listener = new BeforeServerStartListener();

        $listener->process(new AfterServerCreated(
            $server,
            [
                'name' => 'tcp',
                'host' => '127.0.0.1',
                'port' => 9502,
            ],
        ));

        self::assertNull($server->callback(Constant::EVENT_REQUEST));
    }

    public function testRejectsAnAlreadyOwnedRequestCallbackWithoutOverwritingIt(): void
    {
        $server = new NativeServer();
        $callback = static fn(): string => 'owned';
        self::assertTrue($server->on(Constant::EVENT_REQUEST, $callback));
        $listener = new BeforeServerStartListener();

        try {
            $listener->process($this->event($server));
            self::fail('Expected the HTTP request callback claim to be rejected.');
        } catch (RequestCallbackRegistrationException $exception) {
            self::assertStringContainsString('configured Swoole service "http"', $exception->getMessage());
        }

        self::assertSame($callback, $server->callback(Constant::EVENT_REQUEST));
    }

    public function testReportsNativeRegistrationFailure(): void
    {
        $listener = new BeforeServerStartListener();

        $this->expectException(RequestCallbackRegistrationException::class);
        $this->expectExceptionMessageIsOrContains('configured Swoole service "http"');

        $listener->process($this->event(new NativeServer(false)));
    }

    /** @return ListenerConfiguration */
    private function configuration(): array
    {
        return [
            'name'     => 'http',
            'host'     => '127.0.0.1',
            'port'     => 9501,
            'protocol' => Protocol::Http,
        ];
    }

    private function event(
        NativeServer|NativeServerPort|CoroutineServer $server,
    ): AfterServerCreated
    {
        return new AfterServerCreated(
            $server,
            $this->configuration(),
        );
    }
}
