<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\ExceptionDispatcher\ExceptionDispatcher;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerProvider;
use SuperKernel\ExceptionDispatcher\Reporter\FallbackExceptionReporter;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\HttpExceptionHandler;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Callbacks\OnRequestCallback;
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
use SuperKernel\HttpServer\Tests\Fixture\RecordingStdoutLogger;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

use function strlen;

final class HostValidationTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
        ResponseContext::clear();

        parent::tearDown();
    }

    /** @throws Throwable When the fixture cannot be built */
    #[DataProvider('invalidRequests')]
    public function testRejectsHostBeforeRoutingAndLetsTheListenerHandlerRecover(
        string  $target,
        ?string $host,
        bool    $handled,
    ): void
    {
        $logger = new RecordingStdoutLogger();
        $exceptionHandler = $this->createMock(StoppableHttpExceptionHandlerInterface::class);
        $exceptionHandler->expects($handled ? self::once() : self::never())->method('handle')->willReturnCallback(
            static function (Throwable $throwable, ResponseInterface $response) use ($logger): ResponseInterface {
                self::assertInstanceOf(HttpException::class, $throwable);
                self::assertSame(400, $throwable->statusCode);
                self::assertSame('Bad Request', $throwable->getMessage());
                self::assertSame([], $throwable->headers);
                self::assertSame(400, $response->getStatusCode());
                self::assertSame('Bad Request', (string) $response->getBody());
                self::assertFalse(RequestContext::has());
                $logger->warning($throwable->getMessage());

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withBody(new StringStream('{"error":"Bad Request"}'));
            },
        );
        $exceptionHandler->expects($handled ? self::once() : self::never())
            ->method('isPropagationStopped')->willReturn(true);
        $collector = new TestAnnotationCollector([
            Controller::class => [
                ExampleController::class => [
                    new AttributeMetadata(new Controller()),
                ],
            ],
            HttpExceptionHandler::class => [
                $exceptionHandler::class => [
                    new AttributeMetadata(new HttpExceptionHandler(
                        name: $handled ? 'http' : 'other',
                        exception: HttpException::class,
                    )),
                ],
            ],
        ], methodAttributes: [
            ExampleController::class . '::withoutResult' => [
                new AttributeMetadata(new RequestMapping('/user')),
            ],
        ]);
        $registrations = new ExceptionHandlerProvider($collector, new TestContainer([
            $exceptionHandler::class => $exceptionHandler,
        ]));
        $factory = new HttpExceptionDispatcherFactory($registrations, new ExceptionDispatcher(
            $registrations,
            new FallbackExceptionReporter($logger),
        ));
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $container->expects(self::never())->method('has');
        $global = $this->createMock(MiddlewareInterface::class);
        $server = $this->createMock(MiddlewareInterface::class);
        $route = $this->createMock(MiddlewareInterface::class);
        foreach ([
            $global,
            $server,
            $route,
        ] as $middleware) {
            $middleware->expects(self::never())->method('process');
        }
        $pipeline = new RequestHandlerProvider(
            new RouteRegistry($collector),
            new MiddlewareRegistry([
                $global,
            ], [
                'http' => [
                    $server,
                ],
            ], [
                ExampleController::class => [
                    $route,
                ],
            ]),
            new MiddlewareProvider($container, new ResponseConverter(), $factory),
        );
        $callback = new OnRequestCallback(
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
            $pipeline,
            new SwooleRequestAdapter(),
            new SwooleResponseEmitter($logger),
            $factory,
        );
        $native = Request::create();
        $wire = "GET $target HTTP/1.1\r\n" . (null === $host ? '' : "Host: $host\r\n") . "\r\n";
        self::assertSame(strlen($wire), $native->parse($wire));
        $response = $this->createMock(Response::class);
        $response->expects(self::once())->method('status')->with(400, 'Bad Request')->willReturn(true);
        $response->expects(self::once())->method('header')->with('Content-Type', [
            $handled ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8',
        ], false)->willReturn(true);
        $response->expects(self::once())->method('end')
            ->with($handled ? '{"error":"Bad Request"}' : 'Bad Request')->willReturn(true);

        $callback($native, $response);

        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
        self::assertCount(1, $logger->records);
        self::assertSame($handled ? 'warning' : 'error', $logger->records[0]['level']);
        if ($handled) {
            self::assertSame('Bad Request', $logger->records[0]['message']);
        } else {
            self::assertStringStartsWith(HttpException::class . ': Bad Request', $logger->records[0]['message']);
        }
    }

    /** @return iterable<string, array{string, ?string, bool}> */
    public static function invalidRequests(): iterable
    {
        foreach ([
            [
                '/user',
                null,
            ],
            [
                '/index',
                'example.test/user#',
            ],
            [
                '/missing',
                'example.test/user#',
            ],
            [
                '/admin/secret',
                'example.test/index?',
            ],
            [
                '/user',
                'example.test?x=',
            ],
            [
                '/user',
                'example.test:65536',
            ],
            [
                '/user',
                '',
            ],
            [
                '/user',
                "example.test\r\nHost: other.test",
            ],
            [
                '/user',
                '::1',
            ],
        ] as $index => [$target, $host]) {
            foreach ([
                false,
                true,
            ] as $handled) {
                yield $index . ($handled ? ' handled' : ' fallback') => [
                    $target,
                    $host,
                    $handled,
                ];
            }
        }
    }
}
