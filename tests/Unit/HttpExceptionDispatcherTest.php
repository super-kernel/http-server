<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use ArrayObject;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerProvider;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerRegistration;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpServer\Attribute\HttpExceptionHandler;
use SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use SuperKernel\HttpServer\Tests\Fixture\RecordingExceptionDispatcher;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use Throwable;

use function array_map;

final class HttpExceptionDispatcherTest extends TestCase
{
    public function testRejectsHandlersWithoutRegistrationMetadata(): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains('ExceptionHandlerRegistration');
        new HttpExceptionDispatcher([
            $this->createStub(HttpExceptionHandlerInterface::class),
        ], new RecordingExceptionDispatcher());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testDispatchAcceptsOnlyAThrowableAndResponse(): void
    {
        $parameters = new ReflectionClass(HttpExceptionDispatcher::class)
            ->getMethod('dispatch')
            ->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('throwable', $parameters[0]->getName());
        self::assertSame(Throwable::class, (string) $parameters[0]->getType());
        self::assertSame('response', $parameters[1]->getName());
        self::assertSame(ResponseInterface::class, (string) $parameters[1]->getType());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testHandlerContractDoesNotExposeDispatchContext(): void
    {
        $parameters = new ReflectionClass(HttpExceptionHandlerInterface::class)
            ->getMethod('handle')
            ->getParameters();

        self::assertCount(2, $parameters);
        self::assertSame('throwable', $parameters[0]->getName());
        self::assertSame(Throwable::class, (string) $parameters[0]->getType());
        self::assertSame('response', $parameters[1]->getName());
        self::assertSame(ResponseInterface::class, (string) $parameters[1]->getType());
    }

    public function testStoppableHandlerContractUsesTheCurrentThrowableAndResponse(): void
    {
        $reflection = new ReflectionClass(StoppableHttpExceptionHandlerInterface::class);
        $method = $reflection->getMethod('isPropagationStopped');
        $parameters = $method->getParameters();

        self::assertTrue($reflection->isSubclassOf(HttpExceptionHandlerInterface::class));
        self::assertCount(2, $parameters);
        self::assertSame('throwable', $parameters[0]->getName());
        self::assertSame(Throwable::class, (string) $parameters[0]->getType());
        self::assertSame('response', $parameters[1]->getName());
        self::assertSame(ResponseInterface::class, (string) $parameters[1]->getType());
        self::assertSame('bool', (string) $method->getReturnType());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRethrowsTheOriginalThrowableWhenNoHandlersAreRegistered(): void
    {
        $exceptions = new RecordingExceptionDispatcher();
        $dispatcher = self::dispatcher([], [], $exceptions);
        $throwable = new RuntimeException('Sensitive detail.');

        try {
            $dispatcher->dispatch($throwable, new Response());
            self::fail('Expected the original throwable to propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($throwable, $caught);
        }

        self::assertSame([], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('continuingHandlerContracts')]
    public function testContinuesThroughHandlersAndRethrowsIfNoneStops(bool $stoppable): void
    {
        $throwable = new RuntimeException('Original.');
        $exceptions = new RecordingExceptionDispatcher();
        $contract = $stoppable ? StoppableHttpExceptionHandlerInterface::class : HttpExceptionHandlerInterface::class;
        $first = $this->createMock($contract);
        $last = $this->createMock($contract);
        $intermediate = new Response(statusCode: 502, body: 'first');
        $final = new Response(statusCode: 503, body: 'last');
        $first->expects(self::once())->method('handle')
            ->with($throwable, self::isInstanceOf(ResponseInterface::class))
            ->willReturn($intermediate);
        $last->expects(self::once())->method('handle')->with($throwable, $intermediate)->willReturn($final);

        if ($stoppable) {
            $first->expects(self::once())->method('isPropagationStopped')
                ->with($throwable, $intermediate)->willReturn(false);
            $last->expects(self::once())->method('isPropagationStopped')
                ->with($throwable, $final)->willReturn(false);
        }

        $dispatcher = new HttpExceptionDispatcher([
            new ExceptionHandlerRegistration(new HttpExceptionHandler(), $first),
            new ExceptionHandlerRegistration(new HttpExceptionHandler(), $last),
        ], $exceptions);

        try {
            $dispatcher->dispatch($throwable, new Response());
            self::fail('Expected the original throwable after the handler chain was exhausted.');
        } catch (RuntimeException $caught) {
            self::assertSame($throwable, $caught);
        }

        self::assertSame([], $exceptions->dispatches);
    }

    /** @return iterable<string, array{bool}> */
    public static function continuingHandlerContracts(): iterable
    {
        yield 'ordinary handlers' => [
            false,
        ];
        yield 'stoppable handlers returning false' => [
            true,
        ];
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testReturnsTheDetailFreeFallbackOnlyAfterAnExplicitStop(): void
    {
        $exceptions = new RecordingExceptionDispatcher();
        $handler = $this->createStub(StoppableHttpExceptionHandlerInterface::class);
        $handler->method('handle')->willReturnArgument(1);
        $handler->method('isPropagationStopped')->willReturn(true);
        $dispatcher = new HttpExceptionDispatcher([
            new ExceptionHandlerRegistration(new HttpExceptionHandler(), $handler),
        ], $exceptions);

        $response = $dispatcher->dispatch(new RuntimeException('Sensitive detail.'), new Response(headers: [
            'X-Existing' => 'preserved',
        ]));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('preserved', $response->getHeaderLine('X-Existing'));
        self::assertSame('Internal Server Error', (string) $response->getBody());
        self::assertSame([], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPreservesAnHttpExceptionAsTheInitialFallbackResponse(): void
    {
        $exceptions = new RecordingExceptionDispatcher();
        $handler = $this->createStub(StoppableHttpExceptionHandlerInterface::class);
        $handler->method('handle')->willReturnArgument(1);
        $handler->method('isPropagationStopped')->willReturn(true);
        $dispatcher = new HttpExceptionDispatcher([
            new ExceptionHandlerRegistration(new HttpExceptionHandler(), $handler),
        ], $exceptions);
        $throwable = new HttpException(422, 'Unprocessable', [
            'X-Recovery' => 'http-exception',
        ]);

        $response = $dispatcher->dispatch($throwable, new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Unprocessable', (string) $response->getBody());
        self::assertSame('http-exception', $response->getHeaderLine('X-Recovery'));
        self::assertSame([], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPassesEveryListenerHandlerResponseToTheNextHandler(): void
    {
        $calls = new ArrayObject();
        $high = new readonly class($calls) implements HttpExceptionHandlerInterface {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                $this->calls->append('high:' . $response->getStatusCode() . ':' . $response->getBody());

                return new Response(statusCode: 502, body: 'high');
            }
        };
        $low = new readonly class($calls) implements StoppableHttpExceptionHandlerInterface {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                $this->calls->append('low:' . $response->getStatusCode() . ':' . $response->getBody());

                return new Response(statusCode: 503, body: 'low');
            }

            public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
            {
                return true;
            }
        };
        $wrongService = new readonly class($calls) implements HttpExceptionHandlerInterface {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                $this->calls->append('wrong-service');

                return new Response(statusCode: 504, body: 'wrong-service');
            }
        };
        $conditional = new readonly class($calls) implements HttpExceptionHandlerInterface {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                if (!$throwable instanceof LogicException) {
                    $this->calls->append('conditional:skipped');

                    return $response;
                }

                $this->calls->append('conditional:handled');

                return new Response(statusCode: 505, body: 'conditional');
            }
        };
        $exceptions = new RecordingExceptionDispatcher();
        $dispatcher = self::dispatcher([
            $low::class => new AttributeMetadata(new HttpExceptionHandler(
                priority : 10,
            )),
            $high::class => new AttributeMetadata(new HttpExceptionHandler(
                priority : 100,
            )),
            $wrongService::class => new AttributeMetadata(new HttpExceptionHandler(
                name     : 'admin',
                priority : 200,
            )),
            $conditional::class => new AttributeMetadata(new HttpExceptionHandler(
                priority : 300,
            )),
        ], [
            $low::class          => $low,
            $high::class         => $high,
            $wrongService::class => $wrongService,
            $conditional::class  => $conditional,
        ], $exceptions);

        $response = $dispatcher->dispatch(new RuntimeException('Failure.'), new Response());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('low', (string) $response->getBody());
        self::assertSame([
            'conditional:skipped',
            'high:500:Internal Server Error',
            'low:502:high',
        ], (array) $calls);
        self::assertSame([], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testStopsAfterAStoppableHandlerReportsPropagationStopped(): void
    {
        $calls = new ArrayObject();
        $continue = new readonly class($calls) implements StoppableHttpExceptionHandlerInterface {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
            {
                $this->calls->append('continue:handle:' . $response->getStatusCode());

                return new Response(statusCode: 501, body: 'continue');
            }

            public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
            {
                $this->calls->append('continue:decision:' . $response->getStatusCode());

                return false;
            }
        };
        $stop = new readonly class($calls) implements StoppableHttpExceptionHandlerInterface {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
            {
                $this->calls->append('stop:handle:' . $response->getStatusCode());

                return new Response(statusCode: 502, body: 'stop');
            }

            public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
            {
                $this->calls->append('stop:decision:' . $response->getStatusCode());

                return true;
            }
        };
        $after = self::handler($calls, 'after', 'after');
        $exceptions = new RecordingExceptionDispatcher();
        $dispatcher = self::dispatcher([
            $continue::class => new AttributeMetadata(new HttpExceptionHandler(
                priority : 300,
            )),
            $stop::class => new AttributeMetadata(new HttpExceptionHandler(
                priority : 200,
            )),
            $after::class => new AttributeMetadata(new HttpExceptionHandler(
                priority : 100,
            )),
        ], [
            $continue::class => $continue,
            $stop::class     => $stop,
            $after::class    => $after,
        ], $exceptions);

        $response = $dispatcher->dispatch(new RuntimeException('Failure.'), new Response());

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('stop', (string) $response->getBody());
        self::assertSame([
            'continue:handle:500',
            'continue:decision:501',
            'stop:handle:501',
            'stop:decision:502',
        ], (array) $calls);
        self::assertSame([], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testReportsAHandlerFailureAndContinuesWithTheLatestResponse(): void
    {
        $failure = new RuntimeException('HTTP handler failure.');
        $failing = new readonly class($failure) implements HttpExceptionHandlerInterface {
            public function __construct(private Throwable $failure)
            {
            }

            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                throw $this->failure;
            }
        };
        $calls = new ArrayObject();
        $successful = self::handler($calls, 'successful', 'recovered');
        $exceptions = new RecordingExceptionDispatcher();
        $dispatcher = self::dispatcher([
            $failing::class => new AttributeMetadata(new HttpExceptionHandler(
                priority : 100,
            )),
            $successful::class => new AttributeMetadata(new HttpExceptionHandler()),
        ], [
            $failing::class    => $failing,
            $successful::class => $successful,
        ], $exceptions);
        $original = new RuntimeException('Original.');

        $response = $dispatcher->dispatch($original, new Response());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame([
            'successful:500:Internal Server Error',
        ], (array) $calls);
        self::assertSame([
            $failure,
        ], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testReportsAStopDecisionFailureAndContinuesWithTheLatestResponse(): void
    {
        $failure = new RuntimeException('Stop decision failure.');
        $failing = new readonly class($failure) implements StoppableHttpExceptionHandlerInterface {
            public function __construct(private Throwable $failure)
            {
            }

            public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
            {
                return new Response(statusCode: 502, body: 'stop decision');
            }

            public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
            {
                throw $this->failure;
            }
        };
        $calls = new ArrayObject();
        $successful = self::handler($calls, 'successful', 'recovered');
        $exceptions = new RecordingExceptionDispatcher();
        $dispatcher = self::dispatcher([
            $failing::class => new AttributeMetadata(new HttpExceptionHandler(
                priority : 100,
            )),
            $successful::class => new AttributeMetadata(new HttpExceptionHandler()),
        ], [
            $failing::class    => $failing,
            $successful::class => $successful,
        ], $exceptions);

        $response = $dispatcher->dispatch(new RuntimeException('Original.'), new Response());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame([
            'successful:502:stop decision',
        ], (array) $calls);
        self::assertSame([
            $failure,
        ], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRethrowsTheOriginalWhenEveryListenerHandlerFails(): void
    {
        $failure = new RuntimeException('HTTP handler failure.');
        $handler = new readonly class($failure) implements HttpExceptionHandlerInterface {
            public function __construct(private Throwable $failure)
            {
            }

            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                throw $this->failure;
            }
        };
        $exceptions = new RecordingExceptionDispatcher();
        $dispatcher = self::dispatcher([
            $handler::class => new AttributeMetadata(new HttpExceptionHandler()),
        ], [
            $handler::class => $handler,
        ], $exceptions);
        $original = new RuntimeException('Original.');

        try {
            $dispatcher->dispatch($original, new Response());
            self::fail('Expected the original throwable after every handler failed.');
        } catch (RuntimeException $caught) {
            self::assertSame($original, $caught);
        }

        self::assertSame([
            $failure,
        ], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsEveryInvalidHttpHandlerDuringFactoryConstruction(): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);
        $this->expectExceptionMessageIsOrContains(HttpExceptionHandlerInterface::class);

        self::dispatcher([
            'Handler' => new AttributeMetadata(new HttpExceptionHandler(
                name : 'admin',
            )),
        ], [
            'Handler' => new stdClass(),
        ], new RecordingExceptionDispatcher());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testFactoryGroupsHandlersByListenerNameDuringConstruction(): void
    {
        $httpLow = new class() implements HttpExceptionHandlerInterface {
            public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
            {
                return $response;
            }
        };
        $httpHigh = new class() implements HttpExceptionHandlerInterface {
            public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
            {
                return $response;
            }
        };
        $admin = new class() implements HttpExceptionHandlerInterface {
            public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
            {
                return $response;
            }
        };
        $provider = new ExceptionHandlerProvider(
            new TestAnnotationCollector([
                HttpExceptionHandler::class => [
                    $httpLow::class => [
                        new AttributeMetadata(new HttpExceptionHandler(
                            priority : 10,
                        )),
                    ],
                    $httpHigh::class => [
                        new AttributeMetadata(new HttpExceptionHandler(
                            priority : 100,
                        )),
                    ],
                    $admin::class => [
                        new AttributeMetadata(new HttpExceptionHandler(
                            name     : 'admin',
                            priority : 50,
                        )),
                    ],
                ],
            ]),
            new TestContainer([
                $httpLow::class  => $httpLow,
                $httpHigh::class => $httpHigh,
                $admin::class    => $admin,
            ]),
        );
        $factory = new HttpExceptionDispatcherFactory($provider, new RecordingExceptionDispatcher());
        $reflection = new ReflectionClass($factory);

        self::assertFalse($reflection->hasProperty('handlerProvider'));
        self::assertSame($factory->forListener('http'), $factory->forListener('http'));
        self::assertSame($factory->forListener('admin'), $factory->forListener('admin'));
        self::assertNotSame($factory->forListener('http'), $factory->forListener('admin'));
        self::assertSame($factory->forListener('missing'), $factory->forListener('missing'));
        self::assertNotSame($factory->forListener('http'), $factory->forListener('missing'));

    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testFactoryCreatesAListenerBoundHttpDispatcher(): void
    {
        $factory = new HttpExceptionDispatcherFactory(
            new ExceptionHandlerProvider(new TestAnnotationCollector(), new TestContainer([])),
            new RecordingExceptionDispatcher(),
        );
        $method = new ReflectionClass($factory)->getMethod('forListener');

        self::assertTrue(new ReflectionClass($factory)->isFinal());
        self::assertTrue(new ReflectionClass($factory)->isReadOnly());
        self::assertCount(1, $method->getParameters());
        self::assertSame('name', $method->getParameters()[0]->getName());
        self::assertSame(
            'string',
            (string) $method->getParameters()[0]->getType(),
        );
        self::assertSame(HttpExceptionDispatcher::class, (string) $method->getReturnType());
        self::assertInstanceOf(
            HttpExceptionDispatcher::class,
            $factory->forListener('http'),
        );
    }

    /** @throws Throwable When declaration discovery or dispatch fails */
    public function testMatchesParentExceptionsAndSkipsBothMethodsOfUnmatchedHandlers(): void
    {
        $unmatched = $this->createMock(StoppableHttpExceptionHandlerInterface::class);
        $unmatched->expects(self::never())->method('handle');
        $unmatched->expects(self::never())->method('isPropagationStopped');
        $matched = $this->createMock(StoppableHttpExceptionHandlerInterface::class);
        $matched->expects(self::once())->method('handle')->willReturnArgument(1);
        $matched->expects(self::once())->method('isPropagationStopped')->willReturn(true);
        $dispatcher = self::dispatcher([
            'Unmatched' => new AttributeMetadata(
                new HttpExceptionHandler(priority: 100, exception: LogicException::class),
            ),
            'Matched' => new AttributeMetadata(new HttpExceptionHandler(exception: RuntimeException::class)),
        ], [
            'Unmatched' => $unmatched,
            'Matched'   => $matched,
        ], new RecordingExceptionDispatcher());

        $response = $dispatcher->dispatch(
            new HttpException(405, 'Method Not Allowed', [
                'Allow' => 'GET',
            ]),
            new Response(),
        );

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }

    /** @throws Throwable When declaration discovery or dispatch fails */
    public function testNoMatchingHandlersRethrowsTheOriginalObjectAndRecoveryUsesAFreshFallback(): void
    {
        $unmatched = $this->createMock(StoppableHttpExceptionHandlerInterface::class);
        $unmatched->expects(self::never())->method('handle');
        $unmatched->expects(self::never())->method('isPropagationStopped');
        $exceptions = new RecordingExceptionDispatcher();
        $dispatcher = self::dispatcher([
            'Unmatched' => new AttributeMetadata(new HttpExceptionHandler(exception: LogicException::class)),
        ], [
            'Unmatched' => $unmatched,
        ], $exceptions);
        $original = new RuntimeException('Private detail');

        try {
            $dispatcher->dispatch($original, new Response());
            self::fail('Expected the original throwable.');
        } catch (RuntimeException $caught) {
            self::assertSame($original, $caught);
        }

        self::assertSame([], $exceptions->dispatches);
        $response = $dispatcher->recover($original, new Response(headers: [
            'X-Unfinished' => 'discard',
        ]));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', (string) $response->getBody());
        self::assertFalse($response->hasHeader('X-Unfinished'));
        self::assertSame([
            $original,
        ], $exceptions->dispatches);
    }

    /** @throws Throwable When declaration discovery or dispatch fails */
    public function testEqualPrioritiesKeepDiscoveryOrderAcrossDifferentMatchingTypes(): void
    {
        $first = $this->createMock(HttpExceptionHandlerInterface::class);
        $second = $this->createMock(StoppableHttpExceptionHandlerInterface::class);
        $intermediate = new Response(statusCode: 422, body: 'first');
        $first->expects(self::once())->method('handle')->willReturn($intermediate);
        $second->expects(
            self::once(),
        )->method('handle')->with(self::isInstanceOf(RuntimeException::class), $intermediate)
            ->willReturnArgument(1);
        $second->expects(self::once())->method('isPropagationStopped')->willReturn(true);
        $dispatcher = self::dispatcher([
            'First' => new AttributeMetadata(
                new HttpExceptionHandler(priority: 10, exception: RuntimeException::class),
            ),
            'Second' => new AttributeMetadata(new HttpExceptionHandler(priority: 10)),
        ], [
            'First'  => $first,
            'Second' => $second,
        ], new RecordingExceptionDispatcher());

        self::assertSame($intermediate, $dispatcher->dispatch(new RuntimeException(), new Response()));
    }

    private static function handler(
        ArrayObject $calls,
        string      $name,
        string      $body,
    ): StoppableHttpExceptionHandlerInterface
    {
        return new readonly class($calls, $name, $body) implements StoppableHttpExceptionHandlerInterface {
            public function __construct(private ArrayObject $calls, private string $name, private string $body)
            {
            }

            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                $this->calls->append(
                    $this->name . ':' . $response->getStatusCode() . ':' . $response->getBody(),
                );

                return new Response(statusCode: 503, body: $this->body);
            }

            public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
            {
                return true;
            }
        };
    }

    /**
     * @param array<class-string, AttributeMetadata<object>> $declarations
     * @param array<class-string, object>                    $handlers
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private static function dispatcher(
        array                        $declarations,
        array                        $handlers,
        RecordingExceptionDispatcher $exceptions,
    ): HttpExceptionDispatcher
    {
        $classes = array_map(
            static fn(AttributeMetadata $declaration): array => [
                $declaration,
            ],
            $declarations,
        );

        $provider = new ExceptionHandlerProvider(
            new TestAnnotationCollector([
                HttpExceptionHandler::class => $classes,
            ]),
            new TestContainer($handlers),
        );

        return new HttpExceptionDispatcherFactory($provider, $exceptions)->forListener('http');
    }
}
