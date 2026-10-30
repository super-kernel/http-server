<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpServer\Constants\RequestAttributeName;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use Throwable;

use function is_array;
use function is_string;

/**
 * Dispatches a routing 404 or 405 through the request listener's HTTP exception chain with a plain-text default.
 *
 * @internal
 */
final class RoutingFailureTerminal
{
    /**
     * Prepares the default from the current response before dispatching. When the chain leaves the identical routing
     * exception unhandled, returns that default without reporting it; a handler stop returns the handler response.
     *
     * @throws InvalidHttpDeclarationException When the request attribute carries no non-empty listener name
     * @throws Throwable                       When listener selection or reporting an HTTP handler failure throws
     */
    public static function respond(
        HttpExceptionDispatcherFactory $dispatcherFactory,
        ServerRequestInterface         $request,
        HttpException                  $exception,
    ): ResponseInterface
    {
        $response = ResponseContext::get();
        $fallback = HttpExceptionFallback::response($exception, $response);

        $configuration = $request->getAttribute(RequestAttributeName::Listener->value);

        if (!is_array($configuration)
            || !is_string($configuration['name'] ?? null)
            || '' === $configuration['name']
        ) {
            throw new InvalidHttpDeclarationException('Routing failure dispatch requires a listener name.');
        }

        try {
            return $dispatcherFactory->forListener($configuration['name'])->dispatch(
                $exception,
                $response,
            );
        } catch (HttpException $unhandled) {
            if ($unhandled !== $exception) {
                throw $unhandled;
            }
        }

        return $fallback;
    }
}
