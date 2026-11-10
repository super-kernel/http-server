<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel HTTP Server

`super-kernel/http-server` serves HTTP on the Swoole server of `super-kernel/server`: it routes requests to controller actions declared with Attributes, runs PSR-15 middleware, turns exceptions into responses, and streams PSR-7 responses back to the client.

## Installation

```bash
composer require super-kernel/http-server
```

The package needs the Swoole extension `>=6.2.3 <6.3`. It serves the listeners that the `super-kernel/server` configuration declares with `Protocol::Http` or `Protocol::WebSocket`, uses the PSR-7 messages of `super-kernel/http-message`, and needs SKERNEL to build the project.

## What it provides

- Routes declared with `#[Controller]` and `#[RequestMapping]`, separate for each named listener, with route variables injected into action parameters.
- Action results converted to responses: PSR-7 responses as they are, arrays as JSON, strings as text.
- PSR-15 middleware for every listener, for one listener, or for one controller.
- Exception handlers per listener, including for `404` and `405`, and a safe `500` for anything unhandled.
- Streaming of file, generated, and event-stream bodies to the client.
- Validation of the `Host` header and conversion of each Swoole request into a PSR-7 `ServerRequest`.

It does not:

- handle WebSocket messages; it serves only the HTTP requests of a WebSocket listener;
- give middleware or controllers the native Swoole request or response;
- provide `psr/http-factory` factories or a PSR-7 implementation of its own;
- serve raw or coroutine-mode listeners.

## Quick start

In an application created from `super-kernel/skeleton`, whose HTTP listener is named `http` and listens on port 9600, add a controller. Save it as `src/Controller/GreetingController.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Controller;

use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\RequestMapping;

#[Controller('/greetings')]
final readonly class GreetingController
{
    #[RequestMapping('/{name}')]
    public function show(string $name): array
    {
        return [
            'greeting' => "Hello, {$name}!",
        ];
    }
}
```

Build the project, start it, and send a request from another terminal:

```bash
skernel build
php bin/bootstrap.php swoole:start
curl http://127.0.0.1:9600/greetings/Ada
```

It prints `{"greeting":"Hello, Ada!"}`, sent with `Content-Type: application/json; charset=utf-8`.

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `SuperKernel\HttpServer\Attribute\Controller` | Attribute | Declares a controller with a route prefix and the listener it serves, `http` by default. |
| `SuperKernel\HttpServer\Attribute\RequestMapping` | Attribute | Maps a public action to a path and an HTTP method; repeatable. |
| `SuperKernel\HttpServer\Constants\HttpMethod` | Enum | The HTTP methods a route accepts, `Get` by default. |
| `SuperKernel\HttpServer\Attribute\Middleware` | Attribute | Declares middleware for every listener, or for the listener it names. |
| `SuperKernel\HttpServer\Attribute\Middlewares` | Attribute | Lists middleware for the routes of one controller. |
| `Psr\Http\Server\MiddlewareInterface` | Interface | Implemented by every middleware. |
| `SuperKernel\HttpServer\Attribute\HttpExceptionHandler` | Attribute | Declares an exception handler for a listener, with a priority and the exception it handles. |
| `SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface` | Interface | Implemented by an exception handler; `handle()` returns a response. |
| `SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface` | Interface | Lets a handler end the chain with its response. |
| `SuperKernel\HttpServer\Exception\HttpException` | Class | An exception with an HTTP status, message, and headers. |
| `SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory` | Class | `forListener()` returns the exception handler chain of a listener. |
| `SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher` | Class | Runs a handler chain; `dispatch()` rethrows when no handler stops it. |
| `Psr\Http\Server\RequestHandlerInterface` | Interface | Provided by the container as the routing and middleware pipeline. |
| `SuperKernel\HttpServer\Constants\RequestAttributeName` | Enum | The request attribute that carries the listener's configuration. |

## Behavior to know

- Every listener configured with `Protocol::Http` or `Protocol::WebSocket` gets this package's `request` callback, even without controllers. If the callback is already set, the server does not start: `SuperKernel\HttpServer\Exception\RequestCallbackRegistrationException` reports `Unable to claim the HTTP request callback for the configured Swoole service "<name>".` Requests with an `Upgrade` header are left to WebSocket handling.
- An unknown path answers `404 Not Found` and a wrong method `405 Method Not Allowed` with one `Allow` header, as plain text, unless an exception handler of the listener stops with its own response.
- An exception that no handler stops is logged and answered with `500 Internal Server Error` without details; an `HttpException` keeps its status, message, and headers. A failing request never ends the worker or affects other requests.
- An invalid, repeated, or, in HTTP/1.1, missing `Host` header is answered with `400 Bad Request` before routing and middleware.
- Controllers, middleware, and exception handlers are checked when the server starts; an invalid declaration raises `SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException`, for example `HTTP middleware <class> declared by <class> must implement Psr\Http\Server\MiddlewareInterface.`
- Routes, middleware, and handlers are found in the build metadata: run `skernel build` again after adding, removing, or changing them, or start with `skernel run`, which builds first.

## Documentation

The developer documentation covers routes, results, middleware, exception handlers, streaming, request conversion, the pipeline outside the server, and what not to do: [SuperKernel HTTP Server](https://github.com/super-kernel/.github/blob/main/docs/components/http-server.md).

## License

Released under the MIT License. See [LICENSE](LICENSE).
