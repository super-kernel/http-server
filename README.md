# SuperKernel HTTP Server

`super-kernel/http-server` provides attribute-driven HTTP routing building blocks and listener-owned Swoole request
callback registration. It owns Swoole request adaptation and PSR-7 response emission, uses HTTP Message's PSR-7 objects
and scoped messages, executes PSR-15 middleware and controller actions, and dispatches application exception handlers.

`BeforeServerStartListener` listens for Server's per-service
`Event\AfterServerCreated` event. For listeners explicitly configured as `Protocol::Http` or
`Protocol::WebSocket`, it checks and exclusively registers Swoole's `request` callback slot on native server and
port services. For every eligible service it calls `SuperKernel\Support\make()` to create a fresh configuration-bound
`Callbacks\OnRequestCallback`; an existing owner is never overwritten, and the top-level callback is never shared across
services. Raw listeners remain available to their owning protocol component. Generic coroutine services are left
unchanged because they do not expose the compatible `on()` API.
`OnRequestCallback` receives the exact listener configuration, PSR `RequestHandlerInterface`, Swoole request adapter,
Swoole response emitter, and `HttpExceptionDispatcherFactory` through its constructor. It does not receive the shared
generic `ExceptionDispatcherInterface` or `ExceptionHandlerProvider`; the factory owns those collaborators and creates
the callback's listener-bound dispatcher from its constructor-grouped handlers. The callback has no `forService()`
rebinding API and does not perform result conversion. Resolving each callback before registration selects its existing
handler group before native startup; injected stateless collaborators may still be shared by the container. Registration
failures identify the affected service through its configured listener name. Requests carrying a non-empty `Upgrade`
header are left untouched.

## Source organization

Paths below are relative to `src/` and the package namespace.

| Directory                        | Responsibility                                                                                                                      |
|----------------------------------|-------------------------------------------------------------------------------------------------------------------------------------|
| `Middleware`                     | `MiddlewareCursor`: immutable per-request position in a compiled middleware plan, plus declared middleware resolution.              |
| `Dispatcher`                     | `HttpExceptionDispatcher`: protocol-specific response recovery, its handler chain, fallbacks, and the routing `404`/`405` terminal. |
| `Factory`                        | `HttpExceptionDispatcherFactory`: builds a dispatcher for one configured listener.                                                  |
| `Registry / Routing`             | Immutable middleware/route registries and controller dispatch.                                                                      |
| `Request / Response / Callbacks` | Native request adaptation, response conversion/emission, and the HTTP callback.                                                     |
| `Constants`                      | `HttpMethod`: route declaration methods; `RequestAttributeName`: request attribute names owned by the pipeline.                     |

Implementation helpers retain an `@internal` boundary; a responsibility namespace does not make them extension points.

### Public namespaces

Use the following component declarations:

- `Middleware\MiddlewareCursor`
- `Dispatcher\HttpExceptionDispatcher`
- `Factory\HttpExceptionDispatcherFactory`
- `Constants\HttpMethod`

## Request and response contexts

The native callback uses `Request\SwooleRequestAdapter` to copy each `Swoole\Http\Request` into a standalone
`SuperKernel\HttpMessage\ServerRequest` and creates one blank `SuperKernel\HttpMessage\Response` for every invocation.
The adapter recursively snapshots native request arrays as scalar, null, and nested-array data, exposes the copied body
through a read-only PSR stream, and rejects objects or resources, including embedded native Swoole HTTP handles. The
callback installs only the resulting PSR messages into HTTP Message's `RequestContext` and `ResponseContext`; neither
message retains or exposes its native Swoole counterpart. The request carries the original listener configuration
array under the `super-kernel.server.listener` attribute, allowing the default pipeline to select routes and middleware
by its `name` field. The handler requires an array with a non-empty string `name` at this PSR boundary; Server's
configuration provider owns validation of all listener fields.

The context classes are typed holders rather than HTTP messages. They store their values through
`SuperKernel\Context\Context` under the matching PSR-7 interface keys: `ServerRequestInterface::class` for requests and
`ResponseInterface::class` for responses. Context selection prefers a Swoole coroutine, then a Swoole thread, and
finally process storage. Native Fibers and Swow coroutines do not create separate contexts; a Fiber outside a Swoole
scope therefore shares the process fallback. The Swoole callback clears only those two HTTP-owned interface keys when
request processing ends, leaving other execution-context values intact. The callback alone retains the Swoole request
and response for the duration of its invocation and passes the Swoole response directly to
`Response\SwooleResponseEmitter`.

`SwooleResponseEmitter` accepts only the PSR response and the Swoole response. It writes exactly the status and repeated
header values supplied by the PSR response, then sends the body in one of three ways chosen from the body's PSR-7
capabilities: a body backed by a regular file goes through Swoole `sendfile()`; a seekable body with a known size is
rewound and passed to the terminal write; every other body, such as an `IterableStream`, a `CallbackStream`, or a pipe,
is read in 64 KiB chunks and written as it is produced, so event streams and large generated downloads reach the client
incrementally and a slow client suspends only the producing coroutine. For file and chunked bodies the emitter drops a
supplied `Content-Length`, because Swoole computes the length of a sent file and uses chunked transfer encoding for
written bodies; Swoole compresses only complete bodies. The emitter does not reinterpret the body from the request
method or response status.

```php
return new Response(headers: ['Content-Type' => 'text/event-stream'], body: new IterableStream($events()));
return new Response(headers: ['Content-Type' => 'application/zip'], body: new FileStream($archivePath));
```

The emitter records a failed Swoole status, header, chunk, file, or terminal write through `StdoutLoggerInterface` and
stops without another write; a failed `sendfile()` falls back to chunked writes while the response is still writable.
It also catches and records throwables from response preparation or emission without propagating them. Logging failures
remain contained, and the emitter never retries because the Swoole response may already be partially mutated. Once
chunks have been written the status and headers cannot change; Swoole finishes an abandoned response when the native
object is released.

`SuperKernel\HttpMessage\Provider\RequestProvider` and `SuperKernel\HttpMessage\Provider\ResponseProvider` are direct DI
providers for the PSR request and response interfaces. They are stateless PSR implementations: each declared method uses
a private delegation path to the message currently stored in its context. A retained provider instance therefore
resolves the current invocation instead of capturing one request or response during container construction.

`RequestHandlerProvider` creates a `Dispatched` request attribute from FastRoute the first time it handles a request.
`Dispatched` is an immutable route result: it carries only the route status, allowed methods, and, for a found route,
the selected `RouteDefinition` and route variables. It never carries middleware or a traversal cursor.

For each request, `RequestHandlerProvider` selects the compiled middleware plan from `MiddlewareRegistry` exactly once,
attaches an immutable, request-local `Middleware\MiddlewareCursor`, and delegates to `MiddlewareProvider` with itself as
the next `RequestHandlerInterface`. `MiddlewareProvider` is the middleware dispatcher: it advances the cursor and
invokes the current middleware with that supplied handler. A middleware continues the chain only by calling the handler,
while returning directly short-circuits both the remaining plan and controller execution. Once the cursor is exhausted,
`MiddlewareProvider` removes it and handles the `Dispatched` fallback: routing `404` and `405` pass through the
current listener's HTTP exception chain and return as responses, while a found route is handled by
lazily resolving `Routing\RouteDispatcher`. The route dispatcher publishes route values, resolves the controller, and
returns its unmodified result. Before returning from the PSR middleware boundary, `MiddlewareProvider`
passes through an existing PSR response and sends every other result, including `null`, through
`Response\ResponseConverter`. `Routing\ControllerArgumentResolver` owns action reflection and HTTP-aware argument
binding.

## Host and request targets

Before creating the PSR request, `SwooleRequestAdapter` validates a present Host as `host[:port]`. It accepts RFC 3986
reg-names (including valid percent escapes), IPv4 addresses, and bracketed IPv6 addresses. Host casing is normalized by
`Uri`; trailing dots are retained. Non-empty ports contain decimal digits only and must be between 0 and 65535.
An empty port is accepted and omitted, as allowed by RFC 3986's `port = *DIGIT`; default ports use `Uri`'s normalization.
Empty hosts, unbracketed IPv6, malformed brackets, invalid ports, whitespace, control characters, and raw
`/`, `?`, `#`, `@`, or backslash in the authority are rejected. IPvFuture literals and IPv6 zone identifiers remain
unsupported by the HTTP Message URI implementation and are rejected at this boundary.

Swoole 6.2.3 overwrites earlier Host values in `Request::$header`. Only for `HTTP/1.0` and `HTTP/1.1`, the adapter reads
`Request::getData()` to check repeated Host field lines case-insensitively and rejects even identical repetitions. It does not
count body text as headers. If raw headers are unavailable, it can validate the exposed authority but cannot recover
field lines discarded by the transport. This check is authority validation, not an application domain allow-list.

An invalid or repeated Host, or a missing Host in HTTP/1.1, throws `HttpException(400, 'Bad Request')` without including
attacker input in the message. HTTP/1.1 requires Host under
[RFC 9112 §3.2](https://www.rfc-editor.org/rfc/rfc9112.html#section-3.2).
`OnRequestCallback` calls its listener-bound `HttpExceptionDispatcher::recover()` before routing or any
global, named-server, or route middleware runs. No controller or action dependency is resolved. At this point there is
no `RequestContext`; handlers can use the throwable and the initial 400 response. An explicit propagation stop returns
the handler's response. Otherwise the existing native-boundary recovery reports the exception and emits a fresh
`400` response with `Content-Type: text/plain; charset=utf-8` and body `Bad Request`. The template's HTTP handler stops
propagation, logs one WARNING, and returns `{"error":"Bad Request"}` with JSON content type.

Scheme, host, port, path, and query are assigned separately through PSR-7 `with*` methods; no concatenated URL is
reparsed. `server['request_uri']` remains the routing path, with `Uri`'s existing encoding: literal `#` or `?` path data
is encoded, existing `%23` and `%3F` escapes stay encoded, and leading `//` is preserved. Swoole separates the first
request-target `?` into `query_string`; subsequent question marks remain query data. A literal `#` in a query is encoded,
not treated as a fragment. On Swoole 6.2.3 an absolute-form target remains literal `request_uri` data (for example,
`http://other.test/user`); the adapter does not reinterpret it as a request for `/user` or replace Host from it.
Full absolute-form target reconstruction is outside this adapter's current contract.

For HTTP/1.0 without Host, the fallback order is `server_name`, `server_addr`, then `localhost`, without inferring the
listening port. Swoole 6.2.3 maps HTTP/2 `:authority` to `header['host']`, so the same authority validation applies.
An HTTP/2 request without authority retains that fallback. HTTP/2 remains disabled by default; this adapter does not
enable it. Requests delegated to the existing Upgrade bypass do not enter this adapter.

## Controllers and routes

Mark a controller with `#[Controller]` and each public action with one or more `#[RequestMapping]` attributes. HTTP
methods are cases of the string-backed `Constants\HttpMethod` enum and default to `HttpMethod::Get`; a method string or
any other value fails at PHP attribute construction instead of being accepted. `Controller::$server` identifies the
named HTTP server and must exactly match the case-sensitive `name()` of its Swoole listener. It defaults to `http`; the
existing first positional argument remains the route prefix.

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

`RouteRegistry` builds an independent FastRoute dispatcher for each declared controller server. The same method and path
may therefore exist on different named HTTP servers without conflicting, and a controller is invisible to all other
servers. Native `request` callback registration remains protocol-driven: every configured HTTP or WebSocket listener
receives its callback even when no controller targets it, in which case unmatched requests return `404`.

Routing and middleware discovery consume the trusted `Controller`, `RequestMapping`, `Middleware`, and `Middlewares`
instances returned by Annotator. HTTP Server validates controller and middleware type relationships and route-table
conflicts, but no longer reconstructs Attribute objects or maintains a component-local Attribute factory.

Route variables are available as PSR-7 request attributes and are injected into action parameters by name. String,
integer, float, boolean, and backed-enum route parameters are converted explicitly. A typed PSR-7 request parameter
receives the current request; other class-typed parameters resolve through the shared SuperKernel container.

Controller actions may return:

- a `Psr\Http\Message\ResponseInterface`, which is emitted unchanged;
- an array or `JsonSerializable`, emitted as JSON;
- a scalar or `Stringable`, emitted as UTF-8 plain text;
- no value (`null`), which returns the response currently stored in `ResponseContext`.

Controller resolution and invocation are performed by `Routing\RouteDispatcher` after every selected middleware has
delegated. `MiddlewareProvider` converts the resulting value before its PSR `process()` method returns, so outer
middleware always receives a `ResponseInterface`. A middleware response that short-circuits the chain never resolves the
route dispatcher or invokes the converter. For supported non-response values, the converter derives an immutable
response from the current `ResponseContext`: it preserves the status and custom headers, replaces the content type and
body, removes a stale `Content-Length`, and does not replace the response stored in the context.

## Middleware

Server-level middleware implements `Psr\Http\Server\MiddlewareInterface`. Use `#[Middleware]` for global middleware on
every HTTP listener, or `#[Middleware(name: 'http')]` to restrict middleware to the listener whose case-sensitive
`name()` is `http`. Global and matching named-server middleware also run for `404` and `405` responses. Use
`#[Middlewares]` on a controller to add route-level controller middleware independent of the accepting server.

The registry services live under `SuperKernel\HttpServer\Registry`. `MiddlewareRegistryProvider` resolves these
middleware objects and produces `MiddlewareRegistry`. When the default request-handler graph is constructed during
startup, the registry uses a priority queue to compile each applicable plan and flattens the result to an object array.
Request handling performs no priority sorting and does not clone or consume the startup queue.
`RequestHandlerProvider` selects the compiled object array, while `MiddlewareProvider` consumes it from the
request-local cursor. The fixed execution order is global middleware, matching named-server middleware, then controller
middleware. Discovery or declaration order is stable within each layer; there is no public numeric middleware priority.
PSR-15 calls unwind in reverse order, so each middleware can perform work before and after delegating to the same
request-handler provider.

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

`RouteRegistry` validates controller declarations, discovers mappings on their public instance methods, builds
controller-prefixed FastRoute groups inside per-server dispatchers, and owns named-server dispatch. It does not retain
or expose the intermediate group declarations after constructing those dispatchers, and it has no middleware dependency.
`MiddlewareRegistry` exposes the raw global, named-server, and route middleware lists, as well as the compiled list for
a server and optional `RouteDefinition`. `RequestHandlerProvider` combines the independent route result and middleware
plan at request time; `MiddlewareProvider` dispatches that plan and owns the exhausted-plan fallback. The immutable
`MiddlewareCursor` attribute is the only traversal position; `Dispatched`, middleware objects, compiled plans, and
retained dependencies remain shared-state free or read-only.

Middleware and controllers resolve through the container. When the request-handler provider is resolved, middleware is
resolved during Server's `AfterServerCreated` dispatch, before its native runtime starts. Middleware construction must
not recurse into registry-owned pipeline services or start the retained `ServerInterface`. Shared middleware objects
must not store request-specific mutable state and must otherwise be safe for concurrent coroutine or thread use. Inject
the corresponding PSR interface when scoped request or response access is required. Native Swoole messages are
callback-owned transport details and are not available to middleware, controllers, or application services.

The HTTP pipeline keeps the current messages in their scoped holders and its route and consumption state on the current
request. Once middleware is exhausted, `MiddlewareProvider` handles unmatched statuses or lazily delegates a found route
to `RouteDispatcher`. It passes every non-response controller result through `ResponseConverter` before returning from
the PSR middleware contract. A controller with no result returns the current response from `ResponseContext`, allowing
middleware responses to unwind to the native callback without discarding earlier response changes.

For a found route, `RouteDispatcher` obtains the declared controller entry from the container and checks its type
before reflecting the action or resolving its dependencies. The entry must be an instance of the declared controller
type; compatible subclasses and proxies are accepted. An unrelated object, scalar, or `null` raises
`InvalidHttpDeclarationException` without invoking the action. The message includes the declared and actual types:

```text
HTTP controller App\Controller\UserController must resolve to a compatible instance, stdClass returned.
```

Returning an unrelated object with the same method name does not satisfy this contract. Non-object entries use this
same declaration exception rather than a native return-type `TypeError`. Exceptions thrown by the container propagate
unchanged. Missing actions retain their reflection declaration error, private and protected actions retain their
native `Error`, and incompatible action arguments retain their native `TypeError`. Neither `404`, `405`, nor a
middleware short-circuit resolves a controller.

## Exception handlers

An HTTP exception handler implements `SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface` and carries
`#[HttpExceptionHandler]`. The Attribute derives from
`SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler`; it exposes listener `name`, `priority`, and an optional third `exception` argument. The name
defaults to `http` and matches the current Swoole listener configuration name case-sensitively. Its inherited throwable
metadata defaults to `Throwable::class`, preserving match-all behavior. Opt into a narrower throwable type with
`#[HttpExceptionHandler(name: 'http', priority: 0, exception: HttpException::class)]` after importing
`SuperKernel\HttpServer\Exception\HttpException`. Matching uses Exception Dispatcher's shared `is_a` rule, so subclasses
match too. Non-matching handlers receive neither `handle()` nor `isPropagationStopped()` calls. Multiple handlers may
belong to one listener; higher priorities run first and equal priorities retain discovery order.

Match-all handlers receive routing failures as described below. The following handler restricts matching
to DomainException and its subclasses through declaration metadata:

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

A handler that can terminate the chain also implements
`SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface`. After `handle()` returns, the dispatcher calls
`isPropagationStopped()` with the same throwable and the returned response. Only a `true` result completes recovery
and returns the response, preventing every remaining listener-bound handler from running. A `false` result or a handler
without this interface continues to the next handler. If the chain ends without an explicit stop, the dispatcher throws
the original throwable instance unchanged, including when the chain is empty. Passing the current invocation values
keeps this decision independent of mutable request state on a shared handler instance.

`HttpExceptionDispatcherFactory` receives Exception Dispatcher's ordered handler provider and the generic exception
dispatcher. Its constructor reads the exact `HttpExceptionHandler` registrations once, validates every local
handler, and groups registrations by declaration `name` while preserving provider order. It does not retain the
provider.
`create(array $configuration)` preserves its existing fresh-dispatcher behavior. The additive
`forListener(string $name): HttpExceptionDispatcher` API returns an immutable dispatcher prepared during factory
construction for that exact name, or a shared empty-chain dispatcher when no handlers are registered. The native
callback and routing fallback use this same API and retained factory, so requests neither reconstruct dispatchers
nor rediscover, regroup, or sort handlers. Inject the factory into consumers; do not construct its service providers.
For example, `$factory->forListener('http')` reuses the HTTP listener's chain.

`HttpExceptionDispatcher::dispatch()` accepts only the throwable and current `ResponseInterface`. It derives the
component-owned `HttpException` response or detail-free generic `500` from that supplied response, then invokes matching
listener-bound handlers in the provider's descending stable priority order. The registration's shared `matches()`
method filters throwable types before either handler method is invoked.
Every successful return becomes the input to the next handler unless a stoppable handler returns `true` from
`isPropagationStopped()`. A failed handler or propagation decision is sent to the generic exception dispatcher and the
remaining handlers continue with the latest response. If no listener-bound handler explicitly stops propagation, the
original throwable is rethrown instead of being sent to the generic dispatcher or returning the fallback response.
Recovery requires `StoppableHttpExceptionHandlerInterface` and an explicit true stop decision.

Direct dispatcher construction accepts only `list<ExceptionHandlerRegistration<ExceptionHandler>>`, in the
supplied order, with each registration containing an HTTP handler. The factory uses this same registration path:

```php
$dispatcher = new SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher([
    new SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerRegistration(
        new HttpExceptionHandler(exception: DomainException::class),
        $domainHandler,
    ),
], $exceptionDispatcher);
```

`HttpExceptionDispatcher::recover()` is the containment adapter used only at the native request boundary. It calls
`dispatch()` and contains an unhandled original throwable or a recovery failure, reports that failure through the
generic dispatcher, and returns a fresh fallback response. Reporting failures are contained too. A valid `HttpException`
retains its status, message, and headers; other throwables receive `500 Internal Server Error` without exception details.
Invalid HTTP exception metadata also falls back to that generic response. Unfinished handler responses and headers are
discarded. Internal callers must keep using `dispatch()` when an unhandled exception should propagate unchanged.

The native `OnRequestCallback` obtains its listener-bound dispatcher through `forListener()` when the callback itself is
constructed. For each request it initializes the blank PSR response before adapting the native request, so adaptation
and PSR request-handler failures reach `recover()` with an available response, even if failing application code cleared
the response context. The callback passes the normal, explicitly recovered, or fallback response through the same
`SwooleResponseEmitter` exactly once. `RequestHandlerProvider` still lets pipeline failures propagate unchanged to that
boundary. A request failure must not escape the native callback and terminate the worker or its other requests. Native
Swoole messages remain private to the callback. The emitter records and contains response preparation and emission
failures without retrying the response. Request and response contexts are cleared in all cases. This boundary contains
catchable throwables; it cannot recover process termination or resource-exhaustion fatal errors.

### Routing failures

At the middleware terminal, an unmatched route becomes `new HttpException(404, 'Not Found')`; a method mismatch
becomes `new HttpException(405, 'Method Not Allowed', ['Allow' => 'POST, GET, HEAD'])` for that example method order.
The actual `Allow` value is **one string**, formed by joining FastRoute's allowed methods in their original order with
`, `. The corresponding PSR header is a one-element list such as `['POST, GET, HEAD']`, so Swoole emits one header
line. This route-specific construction does not change `HttpException::methodNotAllowed()`, whose existing helper
contract stores a list of method strings for application-thrown exceptions.

Routing uses the same matching, priority, initial response preparation, chaining, and explicit-stop rules as
`dispatch()`. A handler returning `true` from `isPropagationStopped()` supplies the final response. With no registered
handlers, no matches, or an exhausted chain without a stop, the terminal returns the original default response:
`404` / `Not Found` or `405` / `Method Not Allowed`, `Content-Type: text/plain; charset=utf-8`, and the original
single-value `Allow` for `405`. Unfinished handler response changes are discarded, including body-stream mutations.
The routing exception itself is not sent to the generic exception dispatcher. Handler and stop-decision failures
still use `dispatch()`'s reporting and continuation; a reporting failure can propagate to the native recovery boundary.

Global and matching named-server middleware also run for `404` and `405` responses. They receive a response when
unwinding, including a response selected by an exception handler; the terminal does not throw the unhandled routing
exception through middleware. Controller-thrown exceptions still escape to `OnRequestCallback::recover()` and retain
its reporting behavior.

`MiddlewareProvider` requires its shared `HttpExceptionDispatcherFactory`. A manually assembled pipeline also
supplies a listener request attribute containing a non-empty string `name`:

```php
$middleware = new MiddlewareProvider($container, $responseConverter, $httpExceptionDispatcherFactory);
$request = $request->withAttribute('super-kernel.server.listener', ['name' => 'http']);
```

Handlers without `exception:` match every throwable, including routing `404` and `405`. A stopping handler's response
replaces the default text response. Continuing handlers retain their side effects, such as logs, while their response
changes are discarded when no handler stops. Restrict matching when only a specific application exception is intended:

```php
#[HttpExceptionHandler(name: 'http', exception: DomainException::class)]
```

Alternatively, a handler that otherwise handles all exceptions can let `404` and `405` continue in its existing
stop decision:

```php
public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
{
    return !($throwable instanceof HttpException && in_array($throwable->statusCode, [404, 405], true));
}
```

This guard also lets application-thrown `404` and `405` continue. It preserves plain-text routing responses but does
not undo side effects already performed in `handle()`; guard that method too or use `exception:` to avoid those calls.

## PSR request handler provider

`SuperKernel\HttpServer\Provider\RequestHandlerProvider` is annotated directly as the DI `Provider` for PSR
`RequestHandlerInterface`. Its constructor receives `RouteRegistry`, `MiddlewareRegistry`, and the dispatching
`MiddlewareInterface`, so resolving the handler constructs the route and middleware pipeline without a forwarding
FactoryProvider. `OnRequestCallback` receives and retains that interface when each native callback is created. Provider
mappings are unique in the current DI component; application code must not declare another `Provider` for
`RequestHandlerInterface` while this component's handler declaration is collected.

## Application template examples

The `super-kernel/skeleton` project template keeps `TestController::test()` at GET `/index` and
`TestController::index()` at GET `/user`.
`/index` increments and returns the service's integer value (`1` on the first request) as text; its AOP example still logs Before and After.
`/user` returns `['id' => 1, 'name' => 'Example']`, which the framework converts to JSON. Neither example writes debug
output or deliberately throws.

`Src\ExceptionHandler\Http\HttpServerExceptionHandler` declares
`#[HttpExceptionHandler(exception: HttpException::class)]`. It logs a warning for the known HTTP exception, formats a
JSON `error` field, retains the supplied status and headers (including `Allow`), and explicitly stops propagation.
An explicit failure in application code can demonstrate this path:

```php
throw new SuperKernel\HttpServer\Exception\HttpException(405, 'Method Not Allowed', ['Allow' => 'GET']);
```

Unknown exceptions bypass that handler, produce one final error log, and receive a generic `500 Internal Server Error`.
Routing failures demonstrate the same handler without changing template code: GET `/missing` returns
`404`, `Content-Type: application/json; charset=utf-8`, and `{"error":"Not Found"}`; DELETE `/user` returns
`405`, the same JSON content type, one `Allow: GET` header, and `{"error":"Method Not Allowed"}`. Each request
adds one WARNING from the template handler. The framework adds no generic error report for either routing failure.

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```
