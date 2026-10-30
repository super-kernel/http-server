<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Callbacks;

use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Response as PsrResponse;
use SuperKernel\HttpServer\Constants\RequestAttributeName;
use SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use SuperKernel\HttpServer\Request\SwooleRequestAdapter;
use SuperKernel\HttpServer\Response\SwooleResponseEmitter;
use SuperKernel\Server\Config\SwooleServerConfigInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

use function is_string;
use function trim;

/**
 * @psalm-import-type ListenerConfiguration from SwooleServerConfigInterface
 */
final readonly class OnRequestCallback
{
    private HttpExceptionDispatcher $httpExceptionDispatcher;

    /**
     * @psalm-param ListenerConfiguration $configuration
     */
    public function __construct(
        private array                   $configuration,
        private RequestHandlerInterface $handler,
        private SwooleRequestAdapter    $requestAdapter,
        private SwooleResponseEmitter   $responseEmitter,
        HttpExceptionDispatcherFactory  $httpExceptionDispatcherFactory,
    )
    {
        $this->httpExceptionDispatcher = $httpExceptionDispatcherFactory->forListener($configuration['name']);
    }

    public function __invoke(Request $request, Response $response): void
    {
        $upgrade = ($request->header ?? [])['upgrade'] ?? null;

        if (is_string($upgrade) && '' !== trim($upgrade)) {
            return;
        }

        try {
            $initialResponse = new PsrResponse();

            try {
                ResponseContext::set($initialResponse);
                $requestContext = $this->requestAdapter->adapt($request)->withAttribute(
                    RequestAttributeName::Listener->value,
                    $this->configuration,
                );

                $requestContext = RequestContext::set($requestContext);
                $serverResponse = $this->handler->handle($requestContext);
            } catch (Throwable $throwable) {
                $serverResponse = $this->httpExceptionDispatcher->recover(
                    $throwable,
                    ResponseContext::has() ? ResponseContext::get() : $initialResponse,
                );
            }

            $this->responseEmitter->emit($serverResponse, $response);
        } finally {
            RequestContext::clear();
            ResponseContext::clear();
        }
    }
}
