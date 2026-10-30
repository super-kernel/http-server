<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use RuntimeException;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\ExceptionDispatcher\ExceptionDispatcher;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerProvider;
use SuperKernel\ExceptionDispatcher\Reporter\FallbackExceptionReporter;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\HttpExceptionHandler;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Callbacks\OnRequestCallback;
use SuperKernel\HttpServer\Constants\HttpMethod;
use SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use SuperKernel\HttpServer\Provider\MiddlewareProvider;
use SuperKernel\HttpServer\Provider\RequestHandlerProvider;
use SuperKernel\HttpServer\Registry\MiddlewareRegistry;
use SuperKernel\HttpServer\Registry\RouteRegistry;
use SuperKernel\HttpServer\Request\SwooleRequestAdapter;
use SuperKernel\HttpServer\Response\ResponseConverter;
use SuperKernel\HttpServer\Response\SwooleResponseEmitter;
use SuperKernel\HttpServer\Tests\Fixture\ExampleController;
use SuperKernel\HttpServer\Tests\Fixture\GlobalRecordingMiddleware;
use SuperKernel\HttpServer\Tests\Fixture\InvocationLog;
use SuperKernel\HttpServer\Tests\Fixture\RecordingStdoutLogger;
use SuperKernel\HttpServer\Tests\Fixture\ServerRecordingMiddleware;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Throwable;

use function count;
use function in_array;
use function strlen;

final class RoutingFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
        ResponseContext::clear();

        parent::tearDown();
    }

    /** @throws Throwable When fixture construction or dispatch fails */
    #[DataProvider('routingScenarios')]
    public function testDispatchesRoutingFailuresInsideTheMiddlewareResponsePath(int $status, string $scenario): void
    {
        $log = new InvocationLog();
        $logger = new RecordingStdoutLogger();
        $message = 404 === $status ? 'Not Found' : 'Method Not Allowed';
        $exceptionHeaders = 405 === $status ? [
            'Allow' => 'POST, GET, HEAD',
        ] : [];
        $initialHeaders = [
            'Content-Type' => [
                'text/plain; charset=utf-8',
            ],
        ];
        if (405 === $status) {
            $initialHeaders['Allow'] = [
                'POST, GET, HEAD',
            ];
        }

        $called = !in_array($scenario, [
            'none',
            'unmatched',
            'other-listener',
        ], true);
        $stops = in_array($scenario, [
            'stop',
            'parent',
        ], true);
        $handleFailure = 'handle-failure' === $scenario;
        $stopFailure = 'stop-failure' === $scenario;
        $handlerFailure = new RuntimeException('Handler failed.');
        $decisionFailure = new LogicException('Stop decision failed.');
        $primary = $this->createMock(StoppableHttpExceptionHandlerInterface::class);
        $primary->expects($called ? self::once() : self::never())->method('handle')->willReturnCallback(
            static function (Throwable $throwable, ResponseInterface $response) use (
                $status,
                $message,
                $exceptionHeaders,
                $initialHeaders,
                $log,
                $logger,
                $handleFailure,
                $handlerFailure,
            ): ResponseInterface {
                self::assertInstanceOf(HttpException::class, $throwable);
                self::assertSame($status, $throwable->statusCode);
                self::assertSame($message, $throwable->getMessage());
                self::assertSame($exceptionHeaders, $throwable->headers);
                self::assertSame($status, $response->getStatusCode());
                self::assertSame($initialHeaders, $response->getHeaders());
                self::assertSame($message, (string) $response->getBody());
                $log->entries[] = 'handler';
                $logger->warning('Application handler ran.');

                if ($handleFailure) {
                    throw $handlerFailure;
                }

                $response->getBody()->write('unfinished mutation');
                ResponseContext::set(new Response(statusCode: 503, body: 'unfinished context'));

                return $response->withStatus(409)
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('X-Handler', 'handled')
                    ->withBody(new StringStream('{"handled":true}'));
            },
        );
        $primary->expects($called && !$handleFailure ? self::once() : self::never())
            ->method('isPropagationStopped')->willReturnCallback(
                static function (Throwable $throwable, ResponseInterface $response) use (
                    $stops,
                    $stopFailure,
                    $decisionFailure,
                ): bool {
                    self::assertInstanceOf(HttpException::class, $throwable);
                    self::assertSame(409, $response->getStatusCode());
                    if ($stopFailure) {
                        throw $decisionFailure;
                    }

                    return $stops;
                },
            );
        $following = $this->createMock(HttpExceptionHandlerInterface::class);
        $following->expects($called && !$stops ? self::once() : self::never())->method('handle')->willReturnCallback(
            static function (
                Throwable         $throwable,
                ResponseInterface $response,
            ) use ($handleFailure, $status, $log): ResponseInterface {
                self::assertInstanceOf(HttpException::class, $throwable);
                self::assertSame($handleFailure ? $status : 409, $response->getStatusCode());
                $log->entries[] = 'following';

                return $response->withHeader('X-Unfinished', 'discard')->withBody(new StringStream('discard'));
            },
        );

        $declarations = [];
        $handlers = [];
        if ('none' !== $scenario) {
            // Register the lower priority handler first to exercise the existing provider ordering.
            if ($called) {
                $declarations[$following::class] = [
                    new AttributeMetadata(new HttpExceptionHandler(priority: 1)),
                ];
                $handlers[$following::class] = $following;
            }
            $declarations[$primary::class] = [
                new AttributeMetadata(new HttpExceptionHandler(
                    name: 'other-listener' === $scenario ? 'admin' : 'http',
                    priority: 100,
                    exception: match ($scenario) {
                        'unmatched' => LogicException::class,
                        'parent'    => RuntimeException::class,
                        default     => Throwable::class,
                    },
                )),
            ];
            $handlers[$primary::class] = $primary;
        }
        $collector = new TestAnnotationCollector([
            HttpExceptionHandler::class => $declarations,
            Controller::class           => [
                ExampleController::class => [
                    new AttributeMetadata(new Controller()),
                ],
            ],
        ], methodAttributes: [
            ExampleController::class . '::withoutResult' => [
                new AttributeMetadata(new RequestMapping('/resource', HttpMethod::Post)),
                new AttributeMetadata(new RequestMapping('/resource', HttpMethod::Get)),
                new AttributeMetadata(new RequestMapping('/resource', HttpMethod::Head)),
            ],
        ]);
        $provider = new ExceptionHandlerProvider($collector, new TestContainer($handlers));
        $factory = new HttpExceptionDispatcherFactory(
            $provider,
            new ExceptionDispatcher($provider, new FallbackExceptionReporter($logger)),
        );
        $middleware = new MiddlewareProvider(new TestContainer([]), new ResponseConverter(), $factory);
        $handler = new RequestHandlerProvider(
            new RouteRegistry($collector),
            new MiddlewareRegistry(
                [
                    new GlobalRecordingMiddleware($log),
                ],
                [
                    'http' => [
                        new ServerRecordingMiddleware($log),
                    ],
                ],
                [],
            ),
            $middleware,
        );
        $callback = new OnRequestCallback(
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
            $handler,
            new SwooleRequestAdapter(),
            new SwooleResponseEmitter($logger),
            $factory,
        );
        self::assertSame(
            $factory->forListener('http'),
            new ReflectionProperty($callback, 'httpExceptionDispatcher')->getValue($callback),
        );
        $request = SwooleRequest::create();
        $rawRequest = 404 === $status
            ? "GET /missing HTTP/1.1\r\nHost: example.test\r\n\r\n"
            : "DELETE /resource HTTP/1.1\r\nHost: example.test\r\n\r\n";
        self::assertSame(strlen($rawRequest), $request->parse($rawRequest));
        $expectedHeaders = $initialHeaders;
        if ($stops) {
            unset($expectedHeaders['Content-Type']);
            $expectedHeaders['Content-Type'] = [
                'application/json',
            ];
            $expectedHeaders['X-Handler'] = [
                'handled',
            ];
        }
        $expectedHeaders['X-Server-Middleware'] = [
            'yes',
        ];
        $expectedHeaders['X-Global-Middleware'] = [
            'yes',
        ];
        $emittedHeaders = [];
        $nativeResponse = $this->createMock(SwooleResponse::class);
        $nativeResponse->expects(self::once())->method('status')
            ->with($stops ? 409 : $status, $stops ? 'Conflict' : $message)->willReturn(true);
        $nativeResponse->expects(self::exactly(count($expectedHeaders)))->method('header')->willReturnCallback(
            static function (string $name, array $values, bool $format) use (&$emittedHeaders): bool {
                self::assertFalse($format);
                self::assertArrayNotHasKey($name, $emittedHeaders);
                $emittedHeaders[$name] = $values;

                return true;
            },
        );
        $nativeResponse->expects(self::once())->method('end')
            ->with($stops ? '{"handled":true}' : $message)->willReturn(true);

        $callback($request, $nativeResponse);

        self::assertSame($expectedHeaders, $emittedHeaders);
        $expectedLog = [
            'global:before',
            'server:before',
        ];
        if ($called) {
            $expectedLog[] = 'handler';
            if (!$stops) {
                $expectedLog[] = 'following';
            }
        }
        $expectedLog[] = 'server:after';
        $expectedLog[] = 'global:after';
        self::assertSame($expectedLog, $log->entries);
        $expectedRecords = $called ? [
            [
                'level'   => 'warning',
                'message' => 'Application handler ran.',
                'context' => [],
            ],
        ] : [];
        if ($handleFailure || $stopFailure) {
            $expectedRecords[] = [
                'level'   => 'error',
                'message' => (string) ($handleFailure ? $handlerFailure : $decisionFailure),
                'context' => [],
            ];
        }
        self::assertSame($expectedRecords, $logger->records);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @return iterable<string, array{int, string}> */
    public static function routingScenarios(): iterable
    {
        foreach ([
            404,
            405,
        ] as $status) {
            foreach ([
                'none',
                'unmatched',
                'continue',
                'stop',
                'handle-failure',
                'stop-failure',
                'other-listener',
                'parent',
                ] as $scenario) {
                yield $status . ' ' . $scenario => [
                    $status,
                    $scenario,
                ];
            }
        }
    }
}
