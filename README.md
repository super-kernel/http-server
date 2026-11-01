<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel HTTP Server

`super-kernel/http-server` provides attribute-driven HTTP routing, PSR-15 middleware, controller actions, and HTTP exception handlers on Swoole. It adapts native Swoole requests to HTTP Message's PSR-7 objects and emits PSR-7 responses back through Swoole.

## Request callbacks

When Server dispatches `Event\AfterServerCreated` for a listener explicitly configured as `Protocol::Http` or `Protocol::WebSocket`, this component registers Swoole's `request` callback on that native server or port. Each listener gets its own callback bound to its configuration; an existing callback owner is never overwritten, and a registration failure raises `RequestCallbackRegistrationException` naming the listener. Raw listeners remain available to their own protocol component, and coroutine servers are left unchanged because they do not expose the compatible `on()` API. Requests carrying a non-empty `Upgrade` header are left untouched.

## Request and response messages

Each native `Swoole\Http\Request` is copied into a standalone `SuperKernel\HttpMessage\ServerRequest`, and one blank `SuperKernel\HttpMessage\Response` is created per request. The copy snapshots native request arrays as scalar, null, and nested-array data, exposes the body through a read-only PSR stream, and rejects objects or resources, including embedded native Swoole HTTP handles. Neither message retains or exposes its native Swoole counterpart: native Swoole messages are not available to middleware, controllers, or application services.

The messages are stored in HTTP Message's `RequestContext` and `ResponseContext` under the `ServerRequestInterface::class` and `ResponseInterface::class` keys of `SuperKernel\Context\Context`, which prefers a Swoole coroutine, then a Swoole thread, then process storage. Native Fibers and Swow coroutines do not create separate contexts. Only those two keys are cleared when request processing ends, leaving other context values intact. Inject `Psr\Http\Message\ServerRequestInterface` or `ResponseInterface` to read the current messages; a retained instance always reaches the current request.

The request carries the original listener configuration array under the `super-kernel.server.listener` attribute, named by `Constants\RequestAttributeName::Listener`, and its `name` field selects routes and middleware. The array must contain a non-empty string `name`.

### Response emission

`Response\SwooleResponseEmitter` writes exactly the status and repeated header values of the PSR response, then sends the body in one of three ways chosen from the body's PSR-7 capabilities: a body backed by a regular file goes through Swoole `sendfile()`; a seekable body with a known size is rewound and passed to the terminal write; every other body, such as an `IterableStream`, a `CallbackStream`, or a pipe, is read in 64 KiB chunks and written as it is produced, so event streams and large generated downloads reach the client incrementally and a slow client suspends only the producing coroutine. For file and chunked bodies a supplied `Content-Length` is dropped, because Swoole computes the length of a sent file and uses chunked transfer encoding for written bodies; Swoole compresses only complete bodies. The body is never reinterpreted from the request method or response status.

```php
return new Response(headers: ['Content-Type' => 'text/event-stream'], body: new IterableStream($events()));
return new Response(headers: ['Content-Type' => 'application/zip'], body: new FileStream($archivePath));
```

A failed Swoole status, header, chunk, file, or terminal write is logged through `StdoutLoggerInterface` and stops emission without another write; a failed `sendfile()` falls back to chunked writes while the response is still writable. Throwables from response preparation or emission are logged rather than propagated, logging failures stay contained, and the response is never retried because it may already be partially written. Once chunks have been written the status and headers cannot change; Swoole finishes an abandoned response when the native object is released.

## Host and request targets

Before the PSR request is created, a present Host is validated as `host[:port]`. RFC 3986 reg-names (including valid percent escapes), IPv4 addresses, and bracketed IPv6 addresses are accepted. Host casing is normalized by `Uri`; trailing dots are kept. Non-empty ports contain decimal digits only and must be between 0 and 65535. An empty port is accepted and omitted, as allowed by RFC 3986's `port = *DIGIT`; default ports use `Uri`'s normalization. Empty hosts, unbracketed IPv6, malformed brackets, invalid ports, whitespace, control characters, and raw `/`, `?`, `#`, `@`, or backslash in the authority are rejected. IPvFuture literals and IPv6 zone identifiers are not supported and are rejected.

Swoole 6.2.3 overwrites earlier Host values in `Request::$header`. For `HTTP/1.0` and `HTTP/1.1` only, the raw request data is checked for repeated Host field lines case-insensitively, and even identical repetitions are rejected; body text is not counted as headers. If raw headers are unavailable, only the exposed authority can be validated. This check is authority validation, not an application domain allow-list.

An invalid or repeated Host, or a missing Host in HTTP/1.1 (required by [RFC 9112 §3.2](https://www.rfc-editor.org/rfc/rfc9112.html#section-3.2)), raises `HttpException(400, 'Bad Request')` without including attacker input in the message. It goes through the listener's HTTP exception handlers before routing or any middleware runs, and no controller or action dependency is resolved. At this point there is no `RequestContext`; handlers receive the throwable and the initial 400 response. An explicit propagation stop returns the handler's response. Otherwise the exception is reported and a fresh `400` response with `Content-Type: text/plain; charset=utf-8` and body `Bad Request` is emitted.

Scheme, host, port, path, and query are assigned separately through PSR-7 `with*` methods; no concatenated URL is reparsed. `server['request_uri']` remains the routing path, with `Uri`'s encoding: literal `#` or `?` path data is encoded, existing `%23` and `%3F` escapes stay encoded, and leading `//` is preserved. Swoole separates the first request-target `?` into `query_string`; later question marks remain query data. A literal `#` in a query is encoded, not treated as a fragment. On Swoole 6.2.3 an absolute-form target remains literal `request_uri` data (for example, `http://other.test/user`); it is not reinterpreted as a request for `/user` and does not replace Host. Absolute-form target reconstruction is not supported.

For HTTP/1.0 without Host, the fallback order is `server_name`, `server_addr`, then `localhost`, without inferring the listening port. Swoole 6.2.3 maps HTTP/2 `:authority` to `header['host']`, so the same authority validation applies, and an HTTP/2 request without authority uses the same fallback. HTTP/2 stays disabled by default. Requests that bypass the callback because of an `Upgrade` header are not adapted.

## Controllers and routes

Mark a controller with `#[Controller]` and each public action with one or more `#[RequestMapping]` attributes. HTTP methods are cases of the string-backed `Constants\HttpMethod` enum and default to `HttpMethod::Get`; a method string or any other value fails at PHP attribute construction. `Controller::$server` identifies the named HTTP server and must exactly match the case-sensitive `name()` of its Swoole listener. It defaults to `http`; the first positional argument is the route prefix.

```php
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Constants\HttpMethod;

#[Controller('/api')]
final readonly class WidgetController
{
    public function __construct(private WidgetRepository $widgets)
    {
    }

    #[RequestMapping('/widgets/{id:\d+}', HttpMethod::Get)]
    public function show(ServerRequestInterface $request, int $id): array
    {
        return [
            'widget' => $this->widgets->get($id),
            'requestId' => $request->getHeaderLine('X-Request-Id'),
        ];
    }
}
```

Use a named argument for another HTTP server:

```php
#[Controller('/admin', server: 'admin')]
final readonly class AdminController
{
}
```

Each named HTTP server has an independent FastRoute dispatcher, so the same method and path may exist on different servers without conflicting, and a controller is invisible to all other servers. Every configured HTTP or WebSocket listener receives its request callback even when no controller targets it; unmatched requests then return `404`. Controller and middleware type relationships and route-table conflicts are validated at startup.

Route variables are available as PSR-7 request attributes and are injected into action parameters by name. String, integer, float, boolean, and backed-enum route parameters are converted explicitly. A typed PSR-7 request parameter receives the current request; other class-typed parameters resolve through the shared SuperKernel container.

Controller actions may return:

- a `Psr\Http\Message\ResponseInterface`, which is emitted unchanged;
- an array or `JsonSerializable`, emitted as JSON;
- a scalar or `Stringable`, emitted as UTF-8 plain text;
- no value (`null`), which returns the response currently stored in `ResponseContext`.

The controller runs after every selected middleware has delegated, and its result is converted before the innermost middleware receives it, so every middleware always receives a `ResponseInterface`. A middleware response that short-circuits the chain never resolves the controller. For supported non-response values, the converted response is derived from the current `ResponseContext`: it keeps the status and custom headers, replaces the content type and body, removes a stale `Content-Length`, and does not replace the response stored in the context.

The controller entry obtained from the container must be an instance of the declared controller type; compatible subclasses and proxies are accepted. An unrelated object, scalar, or `null` raises `InvalidHttpDeclarationException` before the action is reflected or its dependencies resolved, with a message naming the declared and actual types:

```text
HTTP controller App\Controller\UserController must resolve to a compatible instance, stdClass returned.
```

Returning an unrelated object with the same method name does not satisfy this contract. Exceptions thrown by the container propagate unchanged. Missing actions keep their reflection declaration error, private and protected actions their native `Error`, and incompatible action arguments their native `TypeError`. Neither `404`, `405`, nor a middleware short-circuit resolves a controller.

## Middleware

Server-level middleware implements `Psr\Http\Server\MiddlewareInterface`. Use `#[Middleware]` for global middleware on every HTTP listener, or `#[Middleware(name: 'http')]` to restrict middleware to the listener whose case-sensitive `name()` is `http`. Global and matching named-server middleware also run for `404` and `405` responses. Use `#[Middlewares]` on a controller to add route-level controller middleware independent of the accepting server.

The execution order is global middleware, matching named-server middleware, then controller middleware. Discovery or declaration order is stable within each layer; there is no numeric middleware priority. PSR-15 calls unwind in reverse order, so each middleware can perform work before and after delegating to the handler. A middleware continues the chain only by calling the handler; returning directly skips the remaining middleware and the controller.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;

#[Middleware]
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withHeader('X-Request-Id', 'example'));
    }
}

#[Middleware(name: 'http')]
final readonly class HttpAccessLogMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

#[Controller('/admin')]
#[Middlewares([
    AuthenticationMiddleware::class,
    AuthorizationMiddleware::class,
])]
final readonly class AdminController
{
    #[RequestMapping('/dashboard')]
    public function dashboard(): array
    {
        return ['ready' => true];
    }
}
```

Middleware and controllers resolve through the container. Middleware is resolved during Server's `AfterServerCreated` dispatch, before the native runtime starts, so its construction must not request the HTTP pipeline services or start the retained `ServerInterface`. Middleware objects are shared across requests: they must not store request-specific mutable state and must be safe for concurrent coroutine or thread use. Inject the corresponding PSR interface when scoped request or response access is required. A controller with no result returns the current response from `ResponseContext`, so middleware responses unwind without discarding earlier response changes.

## Exception handlers

An HTTP exception handler implements `SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface` and carries `#[HttpExceptionHandler]`. The Attribute derives from `SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler`; it exposes listener `name`, `priority`, and an optional third `exception` argument. The name defaults to `http` and matches the current Swoole listener configuration name case-sensitively. The throwable defaults to `Throwable::class`, matching everything. Opt into a narrower throwable type with `#[HttpExceptionHandler(name: 'http', priority: 0, exception: HttpException::class)]` after importing `SuperKernel\HttpServer\Exception\HttpException`. Subclasses match too. Non-matching handlers receive neither `handle()` nor `isPropagationStopped()` calls. Multiple handlers may belong to one listener; higher priorities run first and equal priorities keep discovery order.

Match-all handlers also receive routing failures, as described below. The following handler restricts matching to DomainException and its subclasses:

```php
use DomainException;
use Psr\Http\Message\ResponseInterface;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpServer\Attribute\HttpExceptionHandler;
use SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface;
use Throwable;

#[HttpExceptionHandler(name: 'admin', exception: DomainException::class)]
final readonly class DomainExceptionHandler implements StoppableHttpExceptionHandlerInterface
{
    public function handle(
        Throwable $throwable,
        ResponseInterface $response,
    ): ResponseInterface
    {
        return new Response(
            statusCode: 422,
            headers: ['Content-Type' => 'text/plain; charset=utf-8'],
            body: 'Domain failure',
        );
    }

    public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
    {
        return true;
    }
}
```

A handler that can terminate the chain also implements `SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface`. After `handle()` returns, `isPropagationStopped()` is called with the same throwable and the returned response. Only a `true` result completes recovery and returns the response, preventing every remaining handler of that listener from running. A `false` result or a handler without this interface continues to the next handler. Passing the current values keeps this decision independent of mutable state on a shared handler instance.

Inject `Factory\HttpExceptionDispatcherFactory` to obtain a listener's dispatcher: `$factory->forListener('http')` returns the immutable dispatcher prepared at startup for that exact name, or a shared empty-chain dispatcher when no handlers are registered. All handlers are validated and grouped by listener when the factory is created.

`Dispatcher\HttpExceptionDispatcher::dispatch()` accepts the throwable and the current `ResponseInterface`. It derives the initial response, the status, message, and headers of an `HttpException`, or a detail-free generic `500`, from the supplied response, then invokes the matching handlers in priority order. Each successful return becomes the input to the next handler unless a stoppable handler stops propagation. A failed handler or propagation decision is reported to the generic exception dispatcher, and the remaining handlers continue with the latest response. If no handler explicitly stops propagation, including when the chain is empty, `dispatch()` rethrows the original throwable unchanged.

A dispatcher can also be constructed directly from registrations, in the supplied order:

```php
$dispatcher = new SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher([
    new SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerRegistration(
        new HttpExceptionHandler(exception: DomainException::class),
        $domainHandler,
    ),
], $exceptionDispatcher);
```

`HttpExceptionDispatcher::recover()` is used only at the native request boundary. It calls `dispatch()`, contains an unhandled throwable or a recovery failure, reports it through the generic dispatcher, and returns a fresh fallback response; reporting failures are contained too. A valid `HttpException` keeps its status, message, and headers; other throwables, and invalid HTTP exception metadata, receive `500 Internal Server Error` without exception details. Unfinished handler responses and headers are discarded. Use `dispatch()` when an unhandled exception should propagate unchanged.

At the native boundary, adaptation and request-handler failures reach `recover()` with a blank response available, even if failing application code cleared the response context, and the normal, recovered, or fallback response is emitted exactly once. A request failure never escapes the native callback to terminate the worker or its other requests, and request and response contexts are cleared in all cases. This boundary contains catchable throwables; it cannot recover process termination or resource-exhaustion fatal errors.

### Routing failures

An unmatched route becomes `new HttpException(404, 'Not Found')`; a method mismatch becomes `new HttpException(405, 'Method Not Allowed', ['Allow' => 'POST, GET, HEAD'])` for that example method order. The `Allow` value is **one string**, joining FastRoute's allowed methods in their original order with `, `, so Swoole emits one header line. This does not change `HttpException::methodNotAllowed()`, which stores a list of method strings for application-thrown exceptions.

Routing failures use the same matching, priority, initial response, chaining, and explicit-stop rules as `dispatch()`. A handler returning `true` from `isPropagationStopped()` supplies the final response. With no registered handlers, no matches, or an exhausted chain without a stop, the default response is returned: `404` / `Not Found` or `405` / `Method Not Allowed`, `Content-Type: text/plain; charset=utf-8`, and the single-value `Allow` for `405`. Unfinished handler response changes are discarded, including body-stream mutations. The routing exception itself is not sent to the generic exception dispatcher; handler and stop-decision failures still are, and a reporting failure can propagate to the native recovery boundary.

Global and matching named-server middleware receive these routing responses when unwinding, including a response selected by an exception handler; the unhandled routing exception is not thrown through middleware. Controller-thrown exceptions still reach the native recovery boundary.

A manually assembled pipeline constructs `Provider\MiddlewareProvider` with the container, a `Response\ResponseConverter`, and the `HttpExceptionDispatcherFactory`, and supplies a listener request attribute containing a non-empty string `name`:

```php
use SuperKernel\HttpServer\Constants\RequestAttributeName;
use SuperKernel\HttpServer\Provider\MiddlewareProvider;

$middleware = new MiddlewareProvider($container, $responseConverter, $httpExceptionDispatcherFactory);
$request = $request->withAttribute(RequestAttributeName::Listener->value, ['name' => 'http']);
```

Handlers without `exception:` match every throwable, including routing `404` and `405`. A stopping handler's response replaces the default text response. Continuing handlers keep their side effects, such as logs, while their response changes are discarded when no handler stops. Restrict matching when only a specific application exception is intended:

```php
#[HttpExceptionHandler(name: 'http', exception: DomainException::class)]
```

Alternatively, a handler that otherwise handles all exceptions can let `404` and `405` continue in its stop decision:

```php
public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
{
    return !($throwable instanceof HttpException && in_array($throwable->statusCode, [404, 405], true));
}
```

This guard also lets application-thrown `404` and `405` continue. It preserves plain-text routing responses but does not undo side effects already performed in `handle()`; guard that method too or use `exception:` to avoid those calls.

## PSR request handler

The container supplies PSR `Psr\Http\Server\RequestHandlerInterface` through `Provider\RequestHandlerProvider`, which runs the route and middleware pipeline. Provider mappings are unique, so application code must not declare another `Provider` for `RequestHandlerInterface`.

## Application template examples

The `super-kernel/skeleton` project template keeps `TestController::test()` at GET `/index` and `TestController::index()` at GET `/user`. `/index` increments and returns the service's integer value (`1` on the first request) as text; its AOP example still logs Before and After. `/user` returns `['id' => 1, 'name' => 'Example']`, which the framework converts to JSON. Neither example writes debug output or deliberately throws.

`Src\ExceptionHandler\Http\HttpServerExceptionHandler` declares `#[HttpExceptionHandler(exception: HttpException::class)]`. It logs a warning for the known HTTP exception, formats a JSON `error` field, keeps the supplied status and headers (including `Allow`), and explicitly stops propagation. For an invalid Host it logs one WARNING and returns `{"error":"Bad Request"}` with a JSON content type. An explicit failure in application code can demonstrate this path:

```php
throw new SuperKernel\HttpServer\Exception\HttpException(405, 'Method Not Allowed', ['Allow' => 'GET']);
```

Unknown exceptions bypass that handler, produce one final error log, and receive a generic `500 Internal Server Error`. Routing failures demonstrate the same handler without changing template code: GET `/missing` returns `404`, `Content-Type: application/json; charset=utf-8`, and `{"error":"Not Found"}`; DELETE `/user` returns `405`, the same JSON content type, one `Allow: GET` header, and `{"error":"Method Not Allowed"}`. Each request adds one WARNING from the template handler. The framework adds no generic error report for either routing failure.
