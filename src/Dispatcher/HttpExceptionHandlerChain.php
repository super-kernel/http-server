<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use Psr\Http\Message\ResponseInterface;
use SuperKernel\Contract\ExceptionDispatcherInterface;
use SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerRegistration;
use SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface;
use Throwable;

/**
 * Chains matching HTTP exception handlers from the throwable's fallback response until one stops propagation.
 *
 * @internal
 */
final class HttpExceptionHandlerChain
{
    /**
     * Reports each handler or stop-decision failure through the exception dispatcher and continues with the latest
     * response; rethrows the original throwable when the chain ends without an explicit stop.
     *
     * @param list<array{
     *     registration: ExceptionHandlerRegistration<ExceptionHandler>,
     *     handler: HttpExceptionHandlerInterface,
     * }> $handlers handlers in provider order
     *
     * @throws Throwable When no handler stops propagation or reporting a handler failure throws
     */
    public static function dispatch(
        array                        $handlers,
        ExceptionDispatcherInterface $exceptionDispatcher,
        Throwable                    $throwable,
        ResponseInterface            $response,
    ): ResponseInterface
    {
        $response = HttpExceptionFallback::response($throwable, $response);

        foreach ($handlers as $entry) {
            if (!$entry['registration']->matches($throwable)) {
                continue;
            }

            $handler = $entry['handler'];

            try {
                $response = $handler->handle($throwable, $response);

                if (
                    $handler instanceof StoppableHttpExceptionHandlerInterface
                    && $handler->isPropagationStopped($throwable, $response)
                ) {
                    return $response;
                }
            } catch (Throwable $handlerFailure) {
                $exceptionDispatcher->dispatch($handlerFailure);
            }
        }

        throw $throwable;
    }
}
