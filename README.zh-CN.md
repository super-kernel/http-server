<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel HTTP Server

`super-kernel/http-server` 在 `super-kernel/server` 的 Swoole 服务器上提供 HTTP 服务：它把请求路由到用注解声明的控制器动作，运行 PSR-15 中间件，把异常转换为响应，并把 PSR-7 响应以流的方式发回客户端。

## 安装

```bash
composer require super-kernel/http-server
```

本包需要 `>=6.2.3 <6.3` 的 Swoole 扩展。它为 `super-kernel/server` 配置中以 `Protocol::Http` 或 `Protocol::WebSocket` 声明的监听器提供服务，使用 `super-kernel/http-message` 的 PSR-7 消息，并需要 SKERNEL 构建项目。

## 提供的能力

- 用 `#[Controller]` 和 `#[RequestMapping]` 声明的路由，按命名监听器分开，路由变量注入到动作参数中。
- 把动作结果转换为响应：PSR-7 响应保持原样，数组转为 JSON，字符串转为文本。
- 作用于所有监听器、某一个监听器或某一个控制器的 PSR-15 中间件。
- 按监听器划分的异常处理器（包括 `404` 和 `405`），以及对任何未处理异常的安全 `500`。
- 把文件、生成的内容和事件流消息体以流的方式发送给客户端。
- 校验 `Host` 头，并把每个 Swoole 请求转换为 PSR-7 `ServerRequest`。

它不会：

- 处理 WebSocket 消息；它只为 WebSocket 监听器上的 HTTP 请求提供服务；
- 把原生 Swoole 请求或响应交给中间件或控制器；
- 提供 `psr/http-factory` 工厂或自己的 PSR-7 实现；
- 为原始监听器或协程模式的监听器提供服务。

## 快速开始

在由 `super-kernel/skeleton` 创建的应用中（其 HTTP 监听器名为 `http`，监听端口 9600），添加一个控制器。将它保存为 `src/Controller/GreetingController.php`：

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

构建项目，启动它，然后在另一个终端中发送请求：

```bash
skernel build
php bin/bootstrap.php swoole:start
curl http://127.0.0.1:9600/greetings/Ada
```

它会打印 `{"greeting":"Hello, Ada!"}`，发送时带有 `Content-Type: application/json; charset=utf-8`。

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\HttpServer\Attribute\Controller` | 注解 | 声明控制器，带有路由前缀和它服务的监听器（默认为 `http`）。 |
| `SuperKernel\HttpServer\Attribute\RequestMapping` | 注解 | 把公开动作映射到路径和 HTTP 方法；可重复。 |
| `SuperKernel\HttpServer\Constants\HttpMethod` | 枚举 | 路由接受的 HTTP 方法，默认为 `Get`。 |
| `SuperKernel\HttpServer\Attribute\Middleware` | 注解 | 为所有监听器或为它指定的监听器声明中间件。 |
| `SuperKernel\HttpServer\Attribute\Middlewares` | 注解 | 为一个控制器的路由列出中间件。 |
| `Psr\Http\Server\MiddlewareInterface` | 接口 | 由每个中间件实现。 |
| `SuperKernel\HttpServer\Attribute\HttpExceptionHandler` | 注解 | 为监听器声明异常处理器，带有优先级和它处理的异常。 |
| `SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface` | 接口 | 由异常处理器实现；`handle()` 返回响应。 |
| `SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface` | 接口 | 让处理器以它的响应结束处理链。 |
| `SuperKernel\HttpServer\Exception\HttpException` | 类 | 带有 HTTP 状态、消息和头的异常。 |
| `SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory` | 类 | `forListener()` 返回某个监听器的异常处理链。 |
| `SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher` | 类 | 运行处理链；没有处理器停止它时，`dispatch()` 重新抛出异常。 |
| `Psr\Http\Server\RequestHandlerInterface` | 接口 | 由容器提供，作为路由和中间件流水线。 |
| `SuperKernel\HttpServer\Constants\RequestAttributeName` | 枚举 | 携带监听器配置的请求属性。 |

## 需要了解的行为

- 在 `Mode::Coroutine` 之外，每个以 `Protocol::Http` 或 `Protocol::WebSocket` 配置的监听器都会获得本包的 `request` 回调，即使没有控制器也是如此。如果该回调已经被设置，服务器不会启动：`SuperKernel\HttpServer\Exception\RequestCallbackRegistrationException` 报告 `Unable to claim the HTTP request callback for the configured Swoole service "<name>".` 带有非空 `Upgrade` 头的请求不会由本包处理，因此 WebSocket 握手会到达 WebSocket 代码。
- 未知路径以 `404 Not Found` 应答，错误的方法以带有一个 `Allow` 头的 `405 Method Not Allowed` 应答，均为纯文本，除非该监听器的某个异常处理器以自己的响应停止处理链。
- 没有处理器停止的异常会被记录，并以不含细节的 `500 Internal Server Error` 应答；`HttpException` 保留它的状态、消息和头。失败的请求永远不会结束工作进程，也不会影响其他请求。
- 无效的、重复的或（在 HTTP/1.1 中）缺失的 `Host` 头会在路由和中间件之前以 `400 Bad Request` 应答。
- 路由路径 `''` 在控制器前缀本身上应答，路由路径 `/` 在前缀后接一个斜杠的路径上应答：对于 `#[Controller('/user')]`，它们分别应答 `/user` 和 `/user/`。
- 无法转换为动作参数类型的路由值（例如 `int` 参数收到的 `abc`，或未知的回退枚举值）以 `400 Bad Request` 应答；监听器的异常处理器收到的是状态为 `400` 的 `HttpException`。名称与路由变量相同、但类型永远无法接收值的参数（例如不是回退枚举的类）会使服务器在启动时停止，消息为 `Route parameter $<name> of <class>::<method>() has type <type>, which cannot receive a route value.`
- 控制器、中间件和异常处理器在服务器启动时检查；无效的声明会抛出 `SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException`，例如 `HTTP middleware <class> declared by <class> must implement Psr\Http\Server\MiddlewareInterface.`
- 路由、中间件和处理器从构建元数据中发现：添加、删除或修改它们之后，请重新运行 `skernel build`，或者用会先构建的 `skernel run` 启动。

## 文档

开发者文档介绍了路由、结果、中间件、异常处理器、流式响应、请求转换、在服务器之外使用流水线以及不要这样做的事项：[SuperKernel HTTP Server](https://github.com/super-kernel/.github/blob/main/docs/components/http-server.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
