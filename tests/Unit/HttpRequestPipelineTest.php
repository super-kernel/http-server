<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\ExceptionDispatcher\ExceptionDispatcher;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerProvider;
use SuperKernel\ExceptionDispatcher\Reporter\FallbackExceptionReporter;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\HttpExceptionHandler;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Callbacks\OnRequestCallback;
use SuperKernel\HttpServer\Constants\HttpMethod;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use SuperKernel\HttpServer\Provider\MiddlewareProvider;
use SuperKernel\HttpServer\Provider\MiddlewareRegistryProvider;
use SuperKernel\HttpServer\Provider\RequestHandlerProvider;
use SuperKernel\HttpServer\Registry\RouteRegistry;
use SuperKernel\HttpServer\Request\SwooleRequestAdapter;
use SuperKernel\HttpServer\Response\ResponseConverter;
use SuperKernel\HttpServer\Response\SwooleResponseEmitter;
use SuperKernel\HttpServer\Routing\ControllerArgumentResolver;
use SuperKernel\HttpServer\Routing\RouteDispatcher;
use SuperKernel\HttpServer\Tests\Fixture\ExampleController;
use SuperKernel\HttpServer\Tests\Fixture\GlobalRecordingMiddleware;
use SuperKernel\HttpServer\Tests\Fixture\GreetingService;
use SuperKernel\HttpServer\Tests\Fixture\InvocationLog;
use SuperKernel\HttpServer\Tests\Fixture\NullStdoutLogger;
use SuperKernel\HttpServer\Tests\Fixture\RecordingHttpExceptionHandler;
use SuperKernel\HttpServer\Tests\Fixture\RouteRecordingMiddleware;
use SuperKernel\HttpServer\Tests\Fixture\ServerRecordingMiddleware;
use SuperKernel\HttpServer\Tests\Fixture\TestAnnotationCollector;
use SuperKernel\HttpServer\Tests\Fixture\TestContainer;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Throwable;

use function json_decode;
use function strlen;

use const JSON_THROW_ON_ERROR;

final class HttpRequestPipelineTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
        ResponseContext::clear();

        parent::tearDown();
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testDispatchesRouteAndRunsGlobalServerThenControllerMiddleware(): void
    {
        $log = new InvocationLog();
        $collector = self::collector();
        $handler = self::application($collector, $log);

        $response = $handler->handle($this->request(
            uri: 'https://example.test/api/widgets/42',
            method: 'GET',
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('yes', $response->getHeaderLine('X-Global-Middleware'));
        self::assertSame('yes', $response->getHeaderLine('X-Server-Middleware'));
        self::assertSame(
            [
                'id'             => 42,
                'name'           => 'widget-42',
                'routeAttribute' => '42',
            ],
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame([
            'global:before',
            'server:before',
            'route:before',
            'controller',
            'route:after',
            'server:after',
            'global:after',
        ], $log->entries);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRunsGlobalAndRouteMiddlewareWithoutMiddlewareForAnotherServer(): void
    {
        $log = new InvocationLog();
        $handler = self::application(self::collector(controllerServer: 'admin'), $log);

        $response = $handler->handle($this->request(
            uri: '/api/widgets/42',
            method: 'GET',
            serverName: 'admin',
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Global-Middleware'));
        self::assertSame('', $response->getHeaderLine('X-Server-Middleware'));
        self::assertSame([
            'global:before',
            'route:before',
            'controller',
            'route:after',
            'global:after',
        ], $log->entries);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testReturnsNotFoundAndMethodNotAllowedResponses(): void
    {
        $handler = self::application(self::collector(), new InvocationLog());

        $notFound = $handler->handle($this->request(uri: '/missing', method: 'GET'));
        $methodNotAllowed = $handler->handle($this->request(uri: '/api/widgets/42', method: 'POST'));

        self::assertSame(404, $notFound->getStatusCode());
        self::assertSame('Not Found', (string) $notFound->getBody());
        self::assertSame('yes', $notFound->getHeaderLine('X-Global-Middleware'));
        self::assertSame('yes', $notFound->getHeaderLine('X-Server-Middleware'));
        self::assertSame(405, $methodNotAllowed->getStatusCode());
        self::assertSame('GET', $methodNotAllowed->getHeaderLine('Allow'));
        self::assertSame('Method Not Allowed', (string) $methodNotAllowed->getBody());
        self::assertSame('yes', $methodNotAllowed->getHeaderLine('X-Global-Middleware'));
        self::assertSame('yes', $methodNotAllowed->getHeaderLine('X-Server-Middleware'));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testConvertsTypedRouteParameters(): void
    {
        $handler = self::application(self::collector(), new InvocationLog());

        $response = $handler->handle($this->request(
            uri: '/api/convert/example/1.5/false/disabled',
            method: 'GET',
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'name'    => 'example',
            'ratio'   => 1.5,
            'enabled' => false,
            'state'   => 'disabled',
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testReturnsTheResponseContextWhenTheControllerHasNoResult(): void
    {
        $handler = self::application(self::collector(), new InvocationLog());

        $response = $handler->handle($this->request(uri: '/api/void', method: 'GET'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testDispatchesControllerFailuresFromTheNativeRequestCallback(): void
    {
        $log = new InvocationLog();
        $collector = self::collector(withExceptionHandlers: true);
        $httpExceptionDispatcherFactory = self::httpExceptionDispatcherFactory($collector, $log);
        $callback = new OnRequestCallback(
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
            self::application($collector, $log),
            new SwooleRequestAdapter(),
            new SwooleResponseEmitter(new NullStdoutLogger()),
            $httpExceptionDispatcherFactory,
        );
        $request = SwooleRequest::create();
        $rawRequest = "GET /api/fail HTTP/1.1\r\nHost: example.test\r\n\r\n";
        $response = $this->createMock(SwooleResponse::class);

        self::assertSame(strlen($rawRequest), $request->parse($rawRequest));
        $response->expects(self::once())
            ->method('status')
            ->with(418, self::isString())
            ->willReturn(true);
        $response->expects(self::never())->method('header');
        $response->expects(self::once())->method('end')->with('handled')->willReturn(true);

        $callback($request, $response);

        self::assertSame([
            'global:before',
            'server:before',
            'route:before',
            'exception:handler',
        ], $log->entries);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testIgnoresAnExceptionHandlerRegisteredForAnotherNativeServiceName(): void
    {
        $log = new InvocationLog();
        $collector = self::collector(
            withExceptionHandlers: true,
            exceptionHandlerName: 'admin',
        );
        $httpExceptionDispatcherFactory = self::httpExceptionDispatcherFactory($collector, $log);
        $callback = new OnRequestCallback(
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
            self::application($collector, $log),
            new SwooleRequestAdapter(),
            new SwooleResponseEmitter(new NullStdoutLogger()),
            $httpExceptionDispatcherFactory,
        );
        $request = SwooleRequest::create();
        $rawRequest = "GET /api/fail HTTP/1.1\r\nHost: example.test\r\n\r\n";
        $response = $this->createMock(SwooleResponse::class);

        self::assertSame(strlen($rawRequest), $request->parse($rawRequest));
        $response->expects(self::once())->method('status')->with(500, 'Internal Server Error')->willReturn(true);
        $response->expects(self::once())->method('header')
            ->with('Content-Type', [
                'text/plain; charset=utf-8',
            ], false)->willReturn(true);
        $response->expects(self::once())->method('end')->with('Internal Server Error')->willReturn(true);

        $callback($request, $response);

        self::assertSame([
            'global:before',
            'server:before',
            'route:before',
        ], $log->entries);
        self::assertFalse(RequestContext::has());
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture construction or dispatch fails */
    #[DataProvider('nativeTargets')]
    public function testRoutesTheNativePathWithoutReinterpretingItsDelimiters(
        string $target,
        string $path,
        int    $status,
    ): void
    {
        $log = new InvocationLog();
        $native = SwooleRequest::create();
        $wire = "GET $target?value=%23%3F&second=? HTTP/1.1\r\nHost: EXAMPLE.TEST.:8080\r\n\r\n";
        self::assertSame(strlen($wire), $native->parse($wire));
        $request = new SwooleRequestAdapter()->adapt($native)->withAttribute(
            'super-kernel.server.listener',
            [
                'name' => 'http',
            ],
        );
        ResponseContext::set(new Response());

        $response = self::application(self::collector(), $log)->handle($request);

        self::assertSame($path, $request->getUri()->getPath());
        self::assertSame('value=%23%3F&second=?', $request->getUri()->getQuery());
        self::assertSame('example.test.', $request->getUri()->getHost());
        self::assertSame(8080, $request->getUri()->getPort());
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Global-Middleware'));
        if (200 === $status) {
            self::assertContains('controller', $log->entries);
            self::assertSame(42, json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['id']);
        } else {
            self::assertNotContains('controller', $log->entries);
            self::assertSame('Not Found', (string) $response->getBody());
        }
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function nativeTargets(): iterable
    {
        return [
            'normal path' => [
                '/api/widgets/42',
                '/api/widgets/42',
                200,
            ],
            'fragment is path data' => [
                '/api/widgets/42#extra',
                '/api/widgets/42%23extra',
                404,
            ],
            'encoded delimiter' => [
                '/api/widgets/42%3Fextra',
                '/api/widgets/42%3Fextra',
                404,
            ],
            'double slash' => [
                '//api/widgets/42',
                '//api/widgets/42',
                404,
            ],
            'absolute form' => [
                'http://other.test/api/widgets/42',
                'http://other.test/api/widgets/42',
                404,
            ],
        ];
    }

    private static function collector(
        bool   $withExceptionHandlers = false,
        string $exceptionHandlerName = 'http',
        string $controllerServer = 'http',
    ): TestAnnotationCollector
    {
        $controller = new AttributeMetadata(new Controller(
            prefix : '/api',
            server : $controllerServer,
        ));
        $globalMiddleware = new AttributeMetadata(new Middleware());
        $serverMiddleware = new AttributeMetadata(new Middleware(
            name : 'http',
        ));
        $routeMiddlewares = new AttributeMetadata(new Middlewares(
            middlewares : [
                RouteRecordingMiddleware::class,
            ],
        ));
        $showRoute = new AttributeMetadata(new RequestMapping(
            path   : '/widgets/{id:\\d+}',
            method : HttpMethod::Get,
        ));
        $failRoute = new AttributeMetadata(new RequestMapping(
            path   : '/fail',
            method : HttpMethod::Get,
        ));
        $conversionRoute = new AttributeMetadata(new RequestMapping(
            path   : '/convert/{name}/{ratio}/{enabled}/{state}',
            method : HttpMethod::Get,
        ));
        $voidRoute = new AttributeMetadata(new RequestMapping(
            path   : '/void',
            method : HttpMethod::Get,
        ));
        $classesByAttribute = [
            Controller::class => [
                ExampleController::class => [
                    $controller,
                ],
            ],
            Middleware::class => [
                GlobalRecordingMiddleware::class => [
                    $globalMiddleware,
                ],
                ServerRecordingMiddleware::class => [
                    $serverMiddleware,
                ],
            ],
        ];

        if ($withExceptionHandlers) {
            $exceptionHandler = new AttributeMetadata(new HttpExceptionHandler(
                name : $exceptionHandlerName,
            ));
            $classesByAttribute[HttpExceptionHandler::class] = [
                RecordingHttpExceptionHandler::class => [
                    $exceptionHandler,
                ],
            ];
        }

        return new TestAnnotationCollector(
            classesByAttribute: $classesByAttribute,
            classAttributes: [
                ExampleController::class => [
                    $controller,
                    $routeMiddlewares,
                ],
            ],
            methodAttributes: [
                ExampleController::class . '::show' => [
                    $showRoute,
                ],
                ExampleController::class . '::fail' => [
                    $failRoute,
                ],
                ExampleController::class . '::convert' => [
                    $conversionRoute,
                ],
                ExampleController::class . '::withoutResult' => [
                    $voidRoute,
                ],
            ],
        );
    }

    /** @throws Throwable When fixture setup or a supplied test callback fails */
    private static function application(
        TestAnnotationCollector $collector,
        InvocationLog           $log,
    ): RequestHandlerProvider
    {
        $entries = [
            ExampleController::class         => new ExampleController($log),
            GlobalRecordingMiddleware::class => new GlobalRecordingMiddleware($log),
            ServerRecordingMiddleware::class => new ServerRecordingMiddleware($log),
            RouteRecordingMiddleware::class  => new RouteRecordingMiddleware($log),
            GreetingService::class           => new GreetingService(),
        ];

        $container = new TestContainer($entries);
        $responseConverter = new ResponseConverter();
        $middlewares = new MiddlewareRegistryProvider()($collector, $container);
        $routeDispatcher = new RouteDispatcher(
            $container,
            new ControllerArgumentResolver($container, new ReflectorProvider()()),
        );

        return new RequestHandlerProvider(
            new RouteRegistry($collector),
            $middlewares,
            new MiddlewareProvider(new TestContainer([
                RouteDispatcher::class => $routeDispatcher,
            ]), $responseConverter, self::httpExceptionDispatcherFactory($collector, $log)),
        );
    }

    /** @throws Throwable When fixture setup or a supplied test callback fails */
    private static function httpExceptionDispatcherFactory(
        TestAnnotationCollector $collector,
        InvocationLog           $log,
    ): HttpExceptionDispatcherFactory
    {
        $container = new TestContainer([
            RecordingHttpExceptionHandler::class => new RecordingHttpExceptionHandler($log),
        ]);
        $provider = new ExceptionHandlerProvider($collector, $container);
        $exceptionDispatcher = new ExceptionDispatcher(
            $provider,
            new FallbackExceptionReporter(
                new NullStdoutLogger(),
            ),
        );

        return new HttpExceptionDispatcherFactory($provider, $exceptionDispatcher);
    }

    private function request(string $uri, string $method, string $serverName = 'http'): ServerRequestInterface
    {
        ResponseContext::set(new Response());

        return new ServerRequest(method: $method, uri: $uri)->withAttribute(
            'super-kernel.server.listener',
            [
                'name' => $serverName,
                'host' => '127.0.0.1',
                'port' => 9501,
            ],
        );
    }
}
