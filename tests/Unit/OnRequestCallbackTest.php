<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Contract\ExceptionDispatcherInterface;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerProvider;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Response as PsrResponse;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpServer\Attribute\HttpExceptionHandler;
use SuperKernel\HttpServer\Callbacks\OnRequestCallback;
use SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use SuperKernel\HttpServer\Request\SwooleRequestAdapter;
use SuperKernel\HttpServer\Response\SwooleResponseEmitter;
use SuperKernel\HttpServer\Tests\Fixture\NullStdoutLogger;
use SuperKernel\HttpServer\Tests\Fixture\RecordingExceptionDispatcher;
use SuperKernel\HttpServer\Tests\Fixture\RecordingStdoutLogger;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use SuperKernel\Server\Config\SwooleServerConfigInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

use function method_exists;
use function strlen;
use function Swoole\Coroutine\run;

/**
 * @psalm-import-type ListenerConfiguration from SwooleServerConfigInterface
 */
final class OnRequestCallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
        ResponseContext::clear();

        parent::tearDown();
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCreatesContextsInvokesThePsrHandlerAndEmitsItsResponse(): void
    {
        $reflection = new ReflectionClass(OnRequestCallback::class);
        $constructor = $reflection->getConstructor();
        $request = Request::create();
        $configuration = [
            'name' => 'http',
            'host' => '127.0.0.1',
            'port' => 9501,
        ];
        $rawRequest = "GET /callback HTTP/1.1\r\nHost: example.test\r\n\r\n";
        $response = $this->createMock(Response::class);
        $handler = new class() implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public ?ResponseInterface $response = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                TestCase::assertInstanceOf(ServerRequest::class, $request);
                TestCase::assertSame($request, RequestContext::get());
                TestCase::assertInstanceOf(PsrResponse::class, ResponseContext::get());

                $this->request = $request;
                $this->response = ResponseContext::get();

                return new PsrResponse(body: 'callback');
            }
        };

        self::assertSame(strlen($rawRequest), $request->parse($rawRequest));
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertFalse($reflection->hasMethod('forService'));
        self::assertFalse($reflection->hasMethod('process'));
        self::assertNotNull($constructor);
        self::assertCount(5, $constructor->getParameters());
        self::assertSame(
            'array',
            (string) $constructor->getParameters()[0]->getType(),
        );
        self::assertSame(RequestHandlerInterface::class, (string) $constructor->getParameters()[1]->getType());
        self::assertSame(SwooleRequestAdapter::class, (string) $constructor->getParameters()[2]->getType());
        self::assertSame(SwooleResponseEmitter::class, (string) $constructor->getParameters()[3]->getType());
        self::assertSame(HttpExceptionDispatcherFactory::class, (string) $constructor->getParameters()[4]->getType());

        $response->expects(self::once())->method('status')->with(200, 'OK')->willReturn(true);
        $response->expects(self::never())->method('header');
        $response->expects(self::once())->method('end')->with('callback')->willReturn(true);

        $this->requestCallback($handler, configuration: $configuration)($request, $response);

        self::assertNotNull($handler->request);
        self::assertNotNull($handler->response);
        self::assertSame(
            $configuration,
            $handler->request->getAttribute('super-kernel.server.listener'),
        );
        self::assertFalse(method_exists($handler->request, 'nativeRequest'));
        self::assertFalse(method_exists($handler->response, 'nativeResponse'));
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPassesTheResponseBodyThroughForAHeadRequest(): void
    {
        $request = new Request();
        $request->server = [
            'request_method' => 'HEAD',
        ];
        $response = $this->createMock(Response::class);
        $handler = new class() implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new PsrResponse(body: 'hidden');
            }
        };

        $response->expects(self::once())->method('status')->with(200, 'OK')->willReturn(true);
        $response->expects(self::never())->method('header');
        $response->expects(self::once())->method('end')->with('hidden')->willReturn(true);

        $this->requestCallback($handler)($request, $response);

        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCreatesTheResponseBeforeRequestAdaptationFails(): void
    {
        $request = new Request();
        $request->server = [
            'unsupported' => new stdClass(),
        ];
        $response = $this->createMock(Response::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $exceptions = new RecordingExceptionDispatcher();
        $httpHandler = $this->createMock(HttpExceptionHandlerInterface::class);
        $httpHandler->expects(self::once())->method('handle')->willReturnCallback(
            static function (Throwable $throwable, ResponseInterface $fallback): ResponseInterface {
                TestCase::assertInstanceOf(InvalidArgumentException::class, $throwable);
                TestCase::assertSame(200, ResponseContext::get()->getStatusCode());
                TestCase::assertSame(500, $fallback->getStatusCode());
                TestCase::assertFalse(RequestContext::has());

                return $fallback;
            },
        );

        $handler->expects(self::never())->method('handle');
        $this->expectFallback($response);

        $this->requestCallback($handler, $exceptions, httpHandlers: [
            $httpHandler::class => [
                new AttributeMetadata(new HttpExceptionHandler()),
                $httpHandler,
            ],
        ])($request, $response);

        self::assertCount(1, $exceptions->dispatches);
        self::assertInstanceOf(InvalidArgumentException::class, $exceptions->dispatches[0]);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testContainsAnUnhandledPsrHandlerFailureAndEmitsAFallback(): void
    {
        $failure = new RuntimeException('Sensitive handler failure.');
        $handler = self::throwingHandler($failure);
        $exceptions = new RecordingExceptionDispatcher();
        $response = $this->createMock(Response::class);
        $this->expectFallback($response);

        $this->requestCallback($handler, $exceptions)(new Request(), $response);

        self::assertSame([
            $failure,
        ], $exceptions->dispatches);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testContainsHttpExceptionRecoveryAndReportingFailures(): void
    {
        $applicationFailure = new RuntimeException('Application failure.');
        $handlerFailure = new RuntimeException('HTTP handler failure.');
        $dispatchFailure = new RuntimeException('Global dispatch failure.');
        $httpHandler = $this->createMock(HttpExceptionHandlerInterface::class);
        $httpHandler->expects(self::once())->method('handle')->willThrowException($handlerFailure);
        $exceptionDispatcher = new class($dispatchFailure) implements ExceptionDispatcherInterface {
            /** @var list<Throwable> */
            public array $received = [];

            public function __construct(private readonly Throwable $failure)
            {
            }

            public function dispatch(Throwable $throwable): void
            {
                $this->received[] = $throwable;

                throw $this->failure;
            }
        };
        $response = $this->createMock(Response::class);
        $this->expectFallback($response);

        $this->requestCallback(
            self::throwingHandler($applicationFailure),
            $exceptionDispatcher,
            httpHandlers: [
                $httpHandler::class => [
                    new AttributeMetadata(new HttpExceptionHandler()),
                    $httpHandler,
                ],
            ],
        )(new Request(), $response);

        self::assertSame([
            $handlerFailure,
            $dispatchFailure,
        ], $exceptionDispatcher->received);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testEmitsTheExplicitlyRecoveredResponse(): void
    {
        $failure = new RuntimeException('Handler failure.');
        $handler = self::throwingHandler($failure);
        $httpHandler = new class() implements StoppableHttpExceptionHandlerInterface {
            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                TestCase::assertSame(500, $response->getStatusCode());

                return new PsrResponse(statusCode: 503, body: 'recovered');
            }

            public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
            {
                return true;
            }
        };
        $exceptions = new RecordingExceptionDispatcher();
        $response = $this->createMock(Response::class);
        $response->expects(self::once())->method('status')->with(503, self::isString())->willReturn(true);
        $response->expects(self::never())->method('header');
        $response->expects(self::once())->method('end')->with('recovered')->willReturn(true);

        $this->requestCallback(
            $handler,
            $exceptions,
            httpHandlers: [
                $httpHandler::class => [
                    new AttributeMetadata(new HttpExceptionHandler()),
                    $httpHandler,
                ],
            ],
        )(new Request(), $response);

        self::assertSame([], $exceptions->dispatches);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRecoversBeforePsrRequestConstruction(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $httpHandler = new class() implements StoppableHttpExceptionHandlerInterface {
            public function handle(
                Throwable         $throwable,
                ResponseInterface $response,
            ): ResponseInterface
            {
                return new PsrResponse(statusCode: 503, body: 'callback recovery');
            }

            public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
            {
                return true;
            }
        };
        $request = new Request();
        $request->server = [
            'request_method' => 'BAD METHOD',
        ];
        $response = $this->createMock(Response::class);
        $response->expects(self::once())->method('status')->with(503, self::isString())->willReturn(true);
        $response->expects(self::never())->method('header');
        $response->expects(self::once())->method('end')->with('callback recovery')->willReturn(true);

        $this->requestCallback(
            $handler,
            configuration: [
                'name' => 'admin',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
            httpHandlers: [
                $httpHandler::class => [
                    new AttributeMetadata(new HttpExceptionHandler(
                        name : 'admin',
                    )),
                    $httpHandler,
                ],
            ],
        )($request, $response);

        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPreservesTheHttpExceptionResponseWhenNoHttpHandlerMatches(): void
    {
        $failure = new HttpException(422, 'Unprocessable', [
            'X-Recovery' => 'http-exception',
        ]);
        $exceptions = new RecordingExceptionDispatcher();
        $response = $this->createMock(Response::class);
        $response->expects(self::once())->method('status')->with(422, 'Unprocessable Content')->willReturn(true);
        $headers = [];
        $response->expects(self::exactly(2))->method('header')->willReturnCallback(
            static function (string $name, array $values) use (&$headers): bool {
                $headers[$name] = $values;

                return true;
            },
        );
        $response->expects(self::once())->method('end')->with('Unprocessable')->willReturn(true);

        $this->requestCallback(self::throwingHandler($failure), $exceptions)(new Request(), $response);

        self::assertSame([
            'http-exception',
        ], $headers['X-Recovery']);
        self::assertSame([
            'text/plain; charset=utf-8',
        ], $headers['Content-Type']);
        self::assertSame([
            $failure,
        ], $exceptions->dispatches);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testLogsSwooleEmissionFailureWithoutRedispatchingIt(): void
    {
        $handler = new class() implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new PsrResponse(
                    headers: [
                        'X-Partial' => 'yes',
                    ],
                    body: 'must not be retried',
                );
            }
        };
        $response = $this->createMock(Response::class);
        $exceptions = new RecordingExceptionDispatcher();
        $logger = new RecordingStdoutLogger();
        $response->expects(self::once())->method('status')->with(200, 'OK')->willReturn(true);
        $response->expects(self::once())
            ->method('header')
            ->with('X-Partial', [
                'yes',
            ], false)
            ->willReturn(false);
        $response->expects(self::never())->method('end');

        $this->requestCallback($handler, $exceptions, logger: $logger)(new Request(), $response);

        self::assertSame([], $exceptions->dispatches);
        self::assertSame([
            [
                'level'   => 'error',
                'message' => 'Unable to emit HTTP header X-Partial.',
                'context' => [],
            ],
        ], $logger->records);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testLogsAResponsePreparationFailureWithoutRetryingIt(): void
    {
        $failure = new RuntimeException('Body read failed.');
        $body = $this->createMock(StreamInterface::class);
        $body->method('getMetadata')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $body->expects(self::once())->method('read')->willThrowException($failure);
        $handler = new readonly class($body) implements RequestHandlerInterface {
            public function __construct(private StreamInterface $body)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new PsrResponse(body: $this->body);
            }
        };
        $response = $this->createMock(Response::class);
        $exceptions = new RecordingExceptionDispatcher();
        $logger = new RecordingStdoutLogger();
        $response->expects(self::once())
            ->method('status')
            ->with(200, 'OK')
            ->willReturn(true);
        $response->expects(self::never())->method('header');
        $response->expects(self::never())->method('end');

        $this->requestCallback($handler, $exceptions, logger: $logger)(new Request(), $response);

        self::assertSame([], $exceptions->dispatches);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('HTTP response emission failed.', $logger->records[0]['message']);
        self::assertSame($failure, $logger->records[0]['context']['exception']);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testLogsEmissionFailureAfterAHandlerStopsPropagation(): void
    {
        $failure = new RuntimeException('Handler failure.');
        $exceptions = new RecordingExceptionDispatcher();
        $logger = new RecordingStdoutLogger();
        $httpHandler = $this->createStub(StoppableHttpExceptionHandlerInterface::class);
        $httpHandler->method('handle')->willReturnArgument(1);
        $httpHandler->method('isPropagationStopped')->willReturn(true);
        $response = $this->createMock(Response::class);
        $response->expects(self::once())
            ->method('status')
            ->with(500, 'Internal Server Error')
            ->willReturn(false);
        $response->expects(self::never())->method('header');
        $response->expects(self::never())->method('end');

        $this->requestCallback(
            self::throwingHandler($failure),
            $exceptions,
            httpHandlers: [
                $httpHandler::class => [
                    new AttributeMetadata(new HttpExceptionHandler()),
                    $httpHandler,
                ],
            ],
            logger: $logger,
        )(new Request(), $response);

        self::assertSame([], $exceptions->dispatches);
        self::assertSame([
            [
                'level'   => 'error',
                'message' => 'Unable to emit HTTP status 500.',
                'context' => [],
            ],
        ], $logger->records);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testSkipsAnHttpProtocolUpgrade(): void
    {
        $request = new Request();
        $request->header = [
            'upgrade' => 'websocket',
        ];
        $response = $this->createMock(Response::class);
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response->expects(self::never())->method('status');
        $response->expects(self::never())->method('end');
        $handler->expects(self::never())->method('handle');

        $this->requestCallback($handler)($request, $response);
    }

    /** @return iterable<string, array{Throwable, bool}> */
    public static function unhandledFailures(): iterable
    {
        yield 'no handler' => [
            new RuntimeException('Sensitive application detail.'),
            false,
        ];
        yield 'handler continues propagation' => [
            new RuntimeException('Sensitive application detail.'),
            true,
        ];
        yield 'PHP error' => [
            new Error('Sensitive engine detail.'),
            false,
        ];
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('unhandledFailures')]
    public function testAnUnhandledFailureDoesNotAffectTheNextRequest(Throwable $failure, bool $continuingHandler): void
    {
        $handler = new class($failure) implements RequestHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly Throwable $failure)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                TestCase::assertSame([], ResponseContext::get()->getHeaders());

                if (1 === $this->calls) {
                    ResponseContext::set(new PsrResponse(headers: [
                        'X-Stale' => 'discard',
                    ]));

                    throw $this->failure;
                }

                return new PsrResponse(body: 'healthy');
            }
        };
        $httpHandlers = [];

        if ($continuingHandler) {
            $httpHandler = $this->createMock(StoppableHttpExceptionHandlerInterface::class);
            $httpHandler->expects(self::once())->method('handle')->willReturn(
                new PsrResponse(
                    statusCode: 418,
                    headers: [
                        'X-Partial' => 'discard',
                    ],
                    body: 'Sensitive partial response.',
                ),
            );
            $httpHandler->expects(self::once())->method('isPropagationStopped')->willReturn(false);
            $httpHandlers[$httpHandler::class] = [
                new AttributeMetadata(new HttpExceptionHandler()),
                $httpHandler,
            ];
        }

        $exceptions = new RecordingExceptionDispatcher();
        $callback = $this->requestCallback($handler, $exceptions, httpHandlers: $httpHandlers);
        $failedResponse = $this->createMock(Response::class);
        $this->expectFallback($failedResponse);

        $callback(new Request(), $failedResponse);

        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());

        $healthyResponse = $this->createMock(Response::class);
        $healthyResponse->expects(self::once())->method('status')->with(200, 'OK')->willReturn(true);
        $healthyResponse->expects(self::never())->method('header');
        $healthyResponse->expects(self::once())->method('end')->with('healthy')->willReturn(true);

        $callback(new Request(), $healthyResponse);

        self::assertSame(2, $handler->calls);
        self::assertSame([
            $failure,
        ], $exceptions->dispatches);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testAnUnhandledFailureDoesNotAffectAnotherActiveRequest(): void
    {
        $completed = false;
        $failure = new RuntimeException('Another request failed.');
        $exceptions = new RecordingExceptionDispatcher();
        $logger = new RecordingStdoutLogger();
        $healthyResponse = new class() extends Response {
            public ?int $statusCode = null;
            /** @var array<string, string|string[]> */
            public array $headers = [];
            public ?string $body = null;

            public function status(int $http_code, string $reason = ''): bool
            {
                $this->statusCode = $http_code;

                return true;
            }

            /** @param string|string[] $value */
            public function header(string $key, array|string $value, bool $format = true): bool
            {
                $this->headers[$key] = $value;

                return true;
            }

            public function end(?string $content = null): bool
            {
                $this->body = $content;

                return true;
            }
        };

        run(function () use (&$completed, $failure, $exceptions, $logger, $healthyResponse): void {
            $ready = new Channel(1);
            $resume = new Channel(1);
            $handler = new readonly class($failure, $ready, $resume) implements RequestHandlerInterface {
                public function __construct(private Throwable $failure, private Channel $ready, private Channel $resume)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    if ('/healthy' !== $request->getUri()->getPath()) {
                        throw $this->failure;
                    }

                    ResponseContext::set(new PsrResponse(headers: [
                        'X-Request' => 'healthy',
                    ]));
                    $this->ready->push(true);
                    $this->resume->pop(1);
                    TestCase::assertSame('/healthy', RequestContext::get()->getUri()->getPath());

                    return ResponseContext::get()->withBody(new StringStream('healthy'));
                }
            };
            $callback = $this->requestCallback($handler, $exceptions, logger: $logger);
            $healthyRequest = new Request();
            $healthyRequest->server = [
                'request_uri' => '/healthy',
            ];
            Coroutine::create(static function () use ($callback, $healthyRequest, $healthyResponse, &$completed): void {
                $callback($healthyRequest, $healthyResponse);
                $completed = true;
            });

            self::assertTrue($ready->pop(1));
            $failedResponse = $this->createMock(Response::class);
            $this->expectFallback($failedResponse);
            $callback(new Request(), $failedResponse);

            self::assertFalse($completed);
            self::assertFalse(RequestContext::has());
            self::assertFalse(ResponseContext::has());
            self::assertTrue($resume->push(true));
        });

        self::assertSame([], $logger->records);
        self::assertTrue($completed);
        self::assertSame(200, $healthyResponse->statusCode);
        self::assertSame([
            'X-Request' => [
                'healthy',
            ],
        ], $healthyResponse->headers);
        self::assertSame('healthy', $healthyResponse->body);
        self::assertSame([
            $failure,
        ], $exceptions->dispatches);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRecoversWhenTheFailingHandlerClearedTheResponseContext(): void
    {
        $failure = new RuntimeException('Response context cleared.');
        $handler = new readonly class($failure) implements RequestHandlerInterface {
            public function __construct(private Throwable $failure)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ResponseContext::clear();

                throw $this->failure;
            }
        };
        $exceptions = new RecordingExceptionDispatcher();
        $response = $this->createMock(Response::class);
        $this->expectFallback($response);

        $this->requestCallback($handler, $exceptions)(new Request(), $response);

        self::assertSame([
            $failure,
        ], $exceptions->dispatches);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testContainsInvalidHttpExceptionResponseMetadata(): void
    {
        $exceptions = new RecordingExceptionDispatcher();
        $response = $this->createMock(Response::class);
        $this->expectFallback($response);

        $this->requestCallback(
            self::throwingHandler(new HttpException(999, 'Invalid response status.')),
            $exceptions,
        )(new Request(), $response);

        self::assertNotEmpty($exceptions->dispatches);
        foreach ($exceptions->dispatches as $failure) {
            self::assertInstanceOf(InvalidArgumentException::class, $failure);
        }
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    private function expectFallback(Response&MockObject $response): void
    {
        $response->expects(self::once())->method('status')->with(500, 'Internal Server Error')->willReturn(true);
        $response->expects(self::once())->method('header')
            ->with('Content-Type', [
                'text/plain; charset=utf-8',
            ], false)->willReturn(true);
        $response->expects(self::once())->method('end')->with('Internal Server Error')->willReturn(true);
    }

    private static function throwingHandler(Throwable $throwable): RequestHandlerInterface
    {
        return new readonly class($throwable) implements RequestHandlerInterface {
            public function __construct(private Throwable $throwable)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->throwable;
            }
        };
    }

    /**
     * @param array<class-string, array{AttributeMetadata<object>, HttpExceptionHandlerInterface}> $httpHandlers
     *
     * @psalm-param ListenerConfiguration|null                                                           $configuration
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private function requestCallback(
        RequestHandlerInterface       $handler,
        ?ExceptionDispatcherInterface $exceptionDispatcher = null,
        ?array                        $configuration = null,
        array                         $httpHandlers = [],
        ?StdoutLoggerInterface        $logger = null,
    ): OnRequestCallback
    {
        $exceptionDispatcher ??= new RecordingExceptionDispatcher();
        $configuration ??= [
            'name' => 'http',
            'host' => '127.0.0.1',
            'port' => 9501,
        ];
        $logger ??= new NullStdoutLogger();
        $declarations = [];
        $handlers = [];

        foreach ($httpHandlers as $class => [$declaration, $httpHandler]) {
            $declarations[$class] = [
                $declaration,
            ];
            $handlers[$class] = $httpHandler;
        }

        $handlerProvider = new ExceptionHandlerProvider(
            new TestAnnotationCollector([
                HttpExceptionHandler::class => $declarations,
            ]),
            new TestContainer($handlers),
        );

        return new OnRequestCallback(
            $configuration,
            $handler,
            new SwooleRequestAdapter(),
            new SwooleResponseEmitter($logger),
            new HttpExceptionDispatcherFactory($handlerProvider, $exceptionDispatcher),
        );
    }
}
