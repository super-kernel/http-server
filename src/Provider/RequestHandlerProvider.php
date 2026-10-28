<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use FastRoute\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\Attribute\Provider;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Middleware\MiddlewareCursor;
use SuperKernel\HttpServer\Registry\MiddlewareRegistry;
use SuperKernel\HttpServer\Registry\RouteRegistry;
use SuperKernel\HttpServer\Routing\Dispatched;

use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

#[Provider(RequestHandlerInterface::class)]
final readonly class RequestHandlerProvider implements RequestHandlerInterface
{
    public function __construct(
        private RouteRegistry       $routes,
        private MiddlewareRegistry  $middlewares,
        private MiddlewareInterface $dispatcher,
    )
    {
    }

    /**
     * @throws InvalidHttpDeclarationException When the listener attribute lacks a non-empty string name
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $configuration = $request->getAttribute('super-kernel.server.listener');

        if (!is_array($configuration)
            || !is_string($configuration['name'] ?? null)
            || '' === $configuration['name']
        ) {
            throw new InvalidHttpDeclarationException(sprintf(
                '%s requires the %s request attribute to be an array with a non-empty string name; %s provided.',
                self::class,
                'super-kernel.server.listener',
                get_debug_type($configuration),
            ));
        }

        $cursor = $request->getAttribute(MiddlewareCursor::class);

        if ($cursor instanceof MiddlewareCursor) {
            return $this->dispatcher->process($request, $this);
        }

        $dispatched = $request->getAttribute(Dispatched::class);

        if (!$dispatched instanceof Dispatched) {
            $dispatched = $this->routes->dispatch(
                $configuration['name'],
                $request->getMethod(),
                $request->getUri()->getPath() ?: '/',
            );
            $request = $request->withAttribute(Dispatched::class, $dispatched);
        }

        $route = Dispatcher::FOUND === $dispatched->status ? $dispatched->handler() : null;
        $middlewares = $this->middlewares->middlewares($configuration['name'], $route);
        $middleware = $middlewares[0] ?? null;

        if ($middleware instanceof MiddlewareInterface) {
            $request = $request->withAttribute(
                MiddlewareCursor::class,
                new MiddlewareCursor($middlewares),
            );
        } elseif (null !== $cursor) {
            $request = $request->withoutAttribute(MiddlewareCursor::class);
        }

        $request = RequestContext::set($request);

        return $this->dispatcher->process($request, $this);
    }
}
