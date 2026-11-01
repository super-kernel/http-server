<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel HTTP Server

`super-kernel/http-server` 在 Swoole 上提供注解驱动的 HTTP 路由、PSR-15 中间件、控制器动作和 HTTP 异常处理器。它把原生的 Swoole 请求适配为 HTTP Message 的 PSR-7 对象，并通过 Swoole 发送 PSR-7 响应。

## 请求回调

当 Server 为一个显式配置为 `Protocol::Http` 或 `Protocol::WebSocket` 的监听器分发 `Event\AfterServerCreated` 时，本组件会在对应的原生服务器或端口上注册 Swoole 的 `request` 回调。每个监听器都有自己的、绑定其配置的回调；已有的回调持有者从不会被覆盖，注册失败时会抛出指明该监听器的 `RequestCallbackRegistrationException`。原始（raw）监听器留给它们自己的协议组件使用；协程服务器保持不变，因为它们没有兼容的 `on()` API。带有非空 `Upgrade` 头的请求不会被处理。

## 请求与响应消息

每个原生 `Swoole\Http\Request` 都会被复制为一个独立的 `SuperKernel\HttpMessage\ServerRequest`，并为每个请求创建一个空白的 `SuperKernel\HttpMessage\Response`。复制时，原生请求数组被快照为标量、null 和嵌套数组数据，请求体通过只读的 PSR 流暴露，对象或资源（包括嵌入的原生 Swoole HTTP 句柄）会被拒绝。两个消息都不会保留或暴露其对应的原生 Swoole 对象：中间件、控制器和应用服务都无法取得原生的 Swoole 消息。

这些消息以 `SuperKernel\Context\Context` 中的 `ServerRequestInterface::class` 和 `ResponseInterface::class` 为键，存放在 HTTP Message 的 `RequestContext` 和 `ResponseContext` 中；上下文依次优先选择 Swoole 协程、Swoole 线程，最后是进程存储。原生 Fiber 和 Swow 协程不会创建独立的上下文。请求处理结束时只清除这两个键，其他上下文值保持不变。注入 `Psr\Http\Message\ServerRequestInterface` 或 `ResponseInterface` 即可读取当前的消息；被保留的实例总是指向当前请求。

请求以 `super-kernel.server.listener` 属性（名称由 `Constants\RequestAttributeName::Listener` 给出）携带原始的监听器配置数组，其 `name` 字段用于选择路由和中间件。该数组必须包含非空字符串的 `name`。

### 响应发送

`Response\SwooleResponseEmitter` 原样写出 PSR 响应的状态码和重复的头部值，然后根据响应体的 PSR-7 能力，以三种方式之一发送响应体：由普通文件支撑的响应体通过 Swoole 的 `sendfile()` 发送；大小已知的可定位响应体先回到开头，再交给最终的一次写入；其他所有响应体，例如 `IterableStream`、`CallbackStream` 或管道，都按 64 KiB 分块读取，并在产生时立即写出，因此事件流和体积很大的生成式下载会逐步到达客户端，慢速客户端只会挂起负责生产的那个协程。对于文件和分块的响应体，会去掉传入的 `Content-Length`，因为 Swoole 会自行计算所发送文件的长度，并对写出的响应体使用分块传输编码；Swoole 只压缩完整的响应体。不会根据请求方法或响应状态重新解释响应体。

```php
return new Response(headers: ['Content-Type' => 'text/event-stream'], body: new IterableStream($events()));
return new Response(headers: ['Content-Type' => 'application/zip'], body: new FileStream($archivePath));
```

Swoole 的状态码、头部、分块、文件或最终写入失败时，会通过 `StdoutLoggerInterface` 记录日志，并停止发送，不再进行其他写入；`sendfile()` 失败时，若响应仍可写，则回退为分块写入。响应准备或发送过程中抛出的异常会被记录而不是向外传播，日志失败也会被拦截，并且从不重试，因为响应可能已经写出了一部分。分块一旦写出，状态码和头部就不能再修改；被放弃的响应会在原生对象释放时由 Swoole 完成。

## Host 与请求目标

在创建 PSR 请求之前，存在的 Host 会按 `host[:port]` 进行校验。接受 RFC 3986 的 reg-name（包括有效的百分号转义）、IPv4 地址和带方括号的 IPv6 地址。Host 的大小写由 `Uri` 规范化；末尾的点会保留。非空端口只能包含十进制数字，且必须在 0 到 65535 之间。空端口按 RFC 3986 的 `port = *DIGIT` 被接受并省略；默认端口使用 `Uri` 的规范化。空主机、不带方括号的 IPv6、格式错误的方括号、无效端口、空白字符、控制字符，以及 authority 中原样出现的 `/`、`?`、`#`、`@` 或反斜杠都会被拒绝。不支持 IPvFuture 字面量和 IPv6 zone 标识，它们会被拒绝。

Swoole 6.2.3 会在 `Request::$header` 中覆盖之前的 Host 值。仅对 `HTTP/1.0` 和 `HTTP/1.1`，会在原始请求数据中不区分大小写地检查重复的 Host 字段行，即使完全相同的重复也会被拒绝；请求体中的文本不会被当作头部。原始头部不可用时，只能校验暴露出来的 authority。这项检查是 authority 校验，而不是应用层的域名白名单。

Host 无效或重复，或 HTTP/1.1 请求缺少 Host（[RFC 9112 §3.2](https://www.rfc-editor.org/rfc/rfc9112.html#section-3.2) 要求必须提供）时，会抛出 `HttpException(400, 'Bad Request')`，消息中不包含攻击者的输入。它会在路由和任何中间件运行之前交给该监听器的 HTTP 异常处理器，不会解析任何控制器或动作依赖。此时还没有 `RequestContext`；处理器收到的是该异常和初始的 400 响应。显式停止传播时返回处理器的响应。否则会报告该异常，并发送一个新的 `400` 响应，带有 `Content-Type: text/plain; charset=utf-8` 和响应体 `Bad Request`。

scheme、host、port、path 和 query 分别通过 PSR-7 的 `with*` 方法设置；不会重新解析拼接出的 URL。`server['request_uri']` 仍作为路由路径，使用 `Uri` 的编码规则：路径中字面的 `#` 或 `?` 会被编码，已有的 `%23` 和 `%3F` 转义保持编码，开头的 `//` 会保留。Swoole 把请求目标中的第一个 `?` 拆分到 `query_string`；之后的问号仍属于查询数据。查询中字面的 `#` 会被编码，而不是被当作片段。在 Swoole 6.2.3 上，absolute-form 的请求目标仍作为字面的 `request_uri` 数据（例如 `http://other.test/user`）；它不会被重新解释为对 `/user` 的请求，也不会替换 Host。不支持 absolute-form 请求目标的重建。

HTTP/1.0 请求没有 Host 时，依次回退到 `server_name`、`server_addr`，最后是 `localhost`，不会推断监听端口。Swoole 6.2.3 把 HTTP/2 的 `:authority` 映射到 `header['host']`，因此同样适用上述 authority 校验；没有 authority 的 HTTP/2 请求使用同样的回退。HTTP/2 默认保持禁用。因带有 `Upgrade` 头而绕过回调的请求不会被适配。

## 控制器与路由

用 `#[Controller]` 标记控制器，并用一个或多个 `#[RequestMapping]` 注解标记每个公开动作。HTTP 方法是字符串枚举 `Constants\HttpMethod` 的成员，默认为 `HttpMethod::Get`；传入方法字符串或其他任何值都会在 PHP 构造注解时失败。`Controller::$server` 指定具名的 HTTP 服务器，必须与其 Swoole 监听器区分大小写的 `name()` 完全一致。它默认为 `http`；第一个位置参数是路由前缀。

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

指定其他 HTTP 服务器时使用命名参数：

```php
#[Controller('/admin', server: 'admin')]
final readonly class AdminController
{
}
```

每个具名 HTTP 服务器都有独立的 FastRoute 调度器，因此相同的方法和路径可以存在于不同的服务器上而不冲突，一个控制器对其他所有服务器都不可见。每个配置的 HTTP 或 WebSocket 监听器都会收到其请求回调，即使没有任何控制器指向它；此时未匹配的请求返回 `404`。控制器和中间件的类型关系以及路由表冲突都在启动时校验。

路由变量以 PSR-7 请求属性的形式提供，并按名称注入到动作参数中。字符串、整数、浮点数、布尔值和带值枚举类型的路由参数会被显式转换。类型为 PSR-7 请求的参数接收当前请求；其他类类型的参数通过共享的 SuperKernel 容器解析。

控制器动作可以返回：

- 一个 `Psr\Http\Message\ResponseInterface`，原样发送；
- 一个数组或 `JsonSerializable`，以 JSON 发送；
- 一个标量或 `Stringable`，以 UTF-8 纯文本发送；
- 没有返回值（`null`），返回当前存放在 `ResponseContext` 中的响应。

控制器在所有被选中的中间件都已委托之后运行，其结果在最内层中间件收到之前就完成转换，因此每个中间件收到的始终是 `ResponseInterface`。短路调用链的中间件响应从不会解析控制器。对于受支持的非响应值，转换后的响应从当前的 `ResponseContext` 派生：保留状态码和自定义头部，替换内容类型和响应体，去掉过期的 `Content-Length`，并且不会替换上下文中存放的响应。

从容器取得的控制器条目必须是所声明控制器类型的实例；兼容的子类和代理也被接受。不相关的对象、标量或 `null` 会在反射动作或解析其依赖之前抛出 `InvalidHttpDeclarationException`，消息中包含声明的类型和实际的类型：

```text
HTTP controller App\Controller\UserController must resolve to a compatible instance, stdClass returned.
```

返回一个具有同名方法但不相关的对象，并不满足这一契约。容器抛出的异常原样传播。缺失的动作保留其反射声明错误，private 和 protected 动作保留其原生的 `Error`，不兼容的动作参数保留其原生的 `TypeError`。`404`、`405` 和中间件短路都不会解析控制器。

## 中间件

服务器级中间件实现 `Psr\Http\Server\MiddlewareInterface`。用 `#[Middleware]` 声明作用于所有 HTTP 监听器的全局中间件，或用 `#[Middleware(name: 'http')]` 把中间件限定在区分大小写的 `name()` 为 `http` 的监听器上。全局中间件和匹配的具名服务器中间件对 `404` 和 `405` 响应也会运行。在控制器上用 `#[Middlewares]` 添加路由级的控制器中间件，它与接收请求的服务器无关。

执行顺序依次是全局中间件、匹配的具名服务器中间件，然后是控制器中间件。每一层内部保持稳定的发现顺序或声明顺序；没有数值形式的中间件优先级。PSR-15 调用按相反的顺序展开，因此每个中间件都可以在委托给处理器之前和之后执行工作。中间件只有调用处理器才会继续调用链；直接返回会跳过其余的中间件和控制器。

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

中间件和控制器都通过容器解析。中间件在 Server 分发 `AfterServerCreated` 期间、原生运行时启动之前解析，因此其构造过程不得请求 HTTP 管线服务，也不得启动被保留的 `ServerInterface`。中间件对象在请求之间共享：它们不得保存与请求相关的可变状态，并且必须能安全地被并发的协程或线程使用。需要访问作用域内的请求或响应时，请注入对应的 PSR 接口。没有返回值的控制器返回 `ResponseContext` 中的当前响应，因此中间件的响应在展开时不会丢失之前对响应所做的修改。

## 异常处理器

HTTP 异常处理器实现 `SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface`，并带有 `#[HttpExceptionHandler]`。该注解派生自 `SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler`；它提供监听器 `name`、`priority` 以及可选的第三个参数 `exception`。名称默认为 `http`，与当前 Swoole 监听器配置的名称区分大小写地匹配。异常类型默认为 `Throwable::class`，即匹配所有异常。导入 `SuperKernel\HttpServer\Exception\HttpException` 之后，可以用 `#[HttpExceptionHandler(name: 'http', priority: 0, exception: HttpException::class)]` 限定为更窄的异常类型。子类同样会被匹配。不匹配的处理器既不会被调用 `handle()`，也不会被调用 `isPropagationStopped()`。一个监听器可以有多个处理器；优先级高的先执行，优先级相同时保持发现顺序。

匹配所有异常的处理器也会收到路由失败，详见下文。下面的处理器把匹配范围限定为 DomainException 及其子类：

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

可以终止调用链的处理器还要实现 `SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface`。`handle()` 返回后，会用同一个异常和返回的响应调用 `isPropagationStopped()`。只有返回 `true` 才会完成恢复并返回该响应，并阻止该监听器的其余所有处理器运行。返回 `false`，或处理器没有实现这个接口时，会继续交给下一个处理器。传入当前的值，可以让这一判断不依赖共享处理器实例上的可变状态。

注入 `Factory\HttpExceptionDispatcherFactory` 来取得某个监听器的分发器：`$factory->forListener('http')` 返回启动时为该确切名称准备好的不可变分发器；没有注册处理器时，返回一个共享的空链分发器。创建工厂时，所有处理器都会被校验并按监听器分组。

`Dispatcher\HttpExceptionDispatcher::dispatch()` 接收异常和当前的 `ResponseInterface`。它从传入的响应派生初始响应：使用 `HttpException` 的状态码、消息和头部，或者一个不含细节的通用 `500`；然后按优先级顺序调用匹配的处理器。每次成功的返回都成为下一个处理器的输入，除非某个可停止的处理器停止了传播。处理器或传播判断失败时，会报告给通用的异常分发器，其余的处理器继续使用最新的响应。如果没有任何处理器显式停止传播（包括调用链为空的情况），`dispatch()` 会原样重新抛出原始异常。

也可以直接用注册项构造分发器，处理器按传入的顺序执行：

```php
$dispatcher = new SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher([
    new SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerRegistration(
        new HttpExceptionHandler(exception: DomainException::class),
        $domainHandler,
    ),
], $exceptionDispatcher);
```

`HttpExceptionDispatcher::recover()` 只在原生请求边界使用。它调用 `dispatch()`，拦截未处理的异常或恢复过程中的失败，通过通用分发器报告，并返回一个新的回退响应；报告时的失败同样会被拦截。有效的 `HttpException` 保留其状态码、消息和头部；其他异常以及无效的 HTTP 异常元数据会得到不含异常细节的 `500 Internal Server Error`。处理器未完成的响应和头部会被丢弃。需要让未处理的异常原样传播时，请使用 `dispatch()`。

在原生边界上，即使出错的应用代码清空了响应上下文，请求适配和请求处理器的失败到达 `recover()` 时仍有一个空白响应可用，正常、恢复后或回退的响应都只会发送一次。请求失败从不会逃出原生回调而终止工作进程或影响其上的其他请求，并且在任何情况下都会清除请求和响应上下文。这一边界拦截的是可捕获的异常，无法从进程终止或资源耗尽类的致命错误中恢复。

### 路由失败

未匹配的路由会变为 `new HttpException(404, 'Not Found')`；方法不匹配时，按示例中的方法顺序会变为 `new HttpException(405, 'Method Not Allowed', ['Allow' => 'POST, GET, HEAD'])`。`Allow` 的值是**一个字符串**，由 FastRoute 允许的方法按原始顺序以 `, ` 连接而成，因此 Swoole 只输出一行该头部。这不会改变 `HttpException::methodNotAllowed()`，后者为应用抛出的异常保存一个方法字符串列表。

路由失败使用与 `dispatch()` 相同的匹配、优先级、初始响应、链式调用和显式停止规则。某个处理器的 `isPropagationStopped()` 返回 `true` 时，由它提供最终响应。没有注册处理器、没有匹配的处理器，或调用链结束时没有停止，则返回默认响应：`404` / `Not Found` 或 `405` / `Method Not Allowed`，带有 `Content-Type: text/plain; charset=utf-8`，`405` 时还带有单值的 `Allow`。处理器未完成的响应修改会被丢弃，包括对响应体流的修改。路由异常本身不会发送给通用的异常分发器；处理器和停止判断的失败仍会发送，报告失败可能会传播到原生的恢复边界。

全局中间件和匹配的具名服务器中间件在展开时会收到这些路由响应，包括由异常处理器选定的响应；未处理的路由异常不会穿过中间件抛出。控制器抛出的异常仍会到达原生的恢复边界。

手动组装的管线需要用容器、一个 `Response\ResponseConverter` 和 `HttpExceptionDispatcherFactory` 构造 `Provider\MiddlewareProvider`，并提供一个包含非空字符串 `name` 的监听器请求属性：

```php
use SuperKernel\HttpServer\Constants\RequestAttributeName;
use SuperKernel\HttpServer\Provider\MiddlewareProvider;

$middleware = new MiddlewareProvider($container, $responseConverter, $httpExceptionDispatcherFactory);
$request = $request->withAttribute(RequestAttributeName::Listener->value, ['name' => 'http']);
```

不带 `exception:` 的处理器匹配所有异常，包括路由产生的 `404` 和 `405`。停止传播的处理器的响应会替换默认的文本响应。继续传播的处理器保留其副作用（例如日志），但没有任何处理器停止传播时，它们对响应的修改会被丢弃。如果只想处理某个特定的应用异常，请限定匹配范围：

```php
#[HttpExceptionHandler(name: 'http', exception: DomainException::class)]
```

或者，一个原本处理所有异常的处理器，可以在其停止判断中让 `404` 和 `405` 继续传播：

```php
public function isPropagationStopped(Throwable $throwable, ResponseInterface $response): bool
{
    return !($throwable instanceof HttpException && in_array($throwable->statusCode, [404, 405], true));
}
```

这个判断也会让应用自己抛出的 `404` 和 `405` 继续传播。它保留了纯文本的路由响应，但不会撤销 `handle()` 中已经产生的副作用；如需避免这些调用，请同样在该方法中加以判断，或使用 `exception:`。

## PSR 请求处理器

容器通过 `Provider\RequestHandlerProvider` 提供 PSR 的 `Psr\Http\Server\RequestHandlerInterface`，它负责运行路由和中间件管线。Provider 映射是唯一的，因此应用代码不得再为 `RequestHandlerInterface` 声明另一个 `Provider`。

## 应用模板示例

`super-kernel/skeleton` 项目模板保留了 GET `/index` 上的 `TestController::test()` 和 GET `/user` 上的 `TestController::index()`。`/index` 递增并以文本返回服务中的整数值（第一次请求返回 `1`）；其 AOP 示例仍会记录 Before 和 After。`/user` 返回 `['id' => 1, 'name' => 'Example']`，由框架转换为 JSON。两个示例都不输出调试信息，也不会有意抛出异常。

`Src\ExceptionHandler\Http\HttpServerExceptionHandler` 声明了 `#[HttpExceptionHandler(exception: HttpException::class)]`。它为已知的 HTTP 异常记录一条警告，生成一个 JSON 的 `error` 字段，保留传入的状态码和头部（包括 `Allow`），并显式停止传播。对于无效的 Host，它记录一条 WARNING，并以 JSON 内容类型返回 `{"error":"Bad Request"}`。在应用代码中显式抛出异常即可演示这一路径：

```php
throw new SuperKernel\HttpServer\Exception\HttpException(405, 'Method Not Allowed', ['Allow' => 'GET']);
```

未知的异常会绕过该处理器，产生一条最终的错误日志，并得到通用的 `500 Internal Server Error`。路由失败无需修改模板代码即可演示同一个处理器：GET `/missing` 返回 `404`、`Content-Type: application/json; charset=utf-8` 和 `{"error":"Not Found"}`；DELETE `/user` 返回 `405`、同样的 JSON 内容类型、一行 `Allow: GET` 头部和 `{"error":"Method Not Allowed"}`。每个请求都会产生一条来自模板处理器的 WARNING。框架不会为这两种路由失败额外输出通用的错误报告。
