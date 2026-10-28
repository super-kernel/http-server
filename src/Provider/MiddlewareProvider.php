<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use FastRoute\Dispatcher;
use JsonException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\Attribute\Provider;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Factory\HttpExceptionDispatcherFactory;
use SuperKernel\HttpServer\Middleware\MiddlewareCursor;
use SuperKernel\HttpServer\Response\ResponseConverter;
use SuperKernel\HttpServer\Routing\Dispatched;
use SuperKernel\HttpServer\Routing\RouteDispatcher;
use Throwable;

use function get_debug_type;
use function implode;
use function is_array;
use function is_string;
use function sprintf;

#[Provider(MiddlewareInterface::class)]
final readonly class MiddlewareProvider implements MiddlewareInterface
{
    public function __construct(
        private ContainerInterface             $container,
        private ResponseConverter              $responseConverter,
        private HttpExceptionDispatcherFactory $httpExceptionDispatcherFactory,
    )
    {
    }

    /**
     * @throws JsonException                   When the controller result cannot be encoded as JSON
     * @throws NotFoundExceptionInterface      When the route dispatcher or controller is not registered
     * @throws ContainerExceptionInterface     When the route dispatcher or controller cannot be resolved
     * @throws InvalidHttpDeclarationException When the dispatch state or controller result is invalid
     * @throws Throwable                       When reporting an HTTP handler failure throws
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cursor = $request->getAttribute(MiddlewareCursor::class);

        if ($cursor instanceof MiddlewareCursor) {
            $middleware = $cursor->current();

            if (null !== $middleware) {
                $request = RequestContext::set($request->withAttribute(
                    MiddlewareCursor::class,
                    $cursor->advance(),
                ));

                return $middleware->process($request, $handler);
            }

            $request = RequestContext::set($request->withoutAttribute(MiddlewareCursor::class));
        }

        $dispatched = $request->getAttribute(Dispatched::class);

        if (!$dispatched instanceof Dispatched) {
            throw new InvalidHttpDeclarationException(sprintf(
                '%s requires the %s request attribute, %s provided.',
                self::class,
                Dispatched::class,
                get_debug_type($dispatched),
            ));
        }

        $result = match ($dispatched->status) {
            Dispatcher::NOT_FOUND          => $this->handleRoutingFailure($request, HttpException::notFound()),
            Dispatcher::METHOD_NOT_ALLOWED => $this->handleRoutingFailure($request, new HttpException(
                405,
                'Method Not Allowed',
                [
                    'Allow' => implode(', ', $dispatched->allowedMethods),
                ],
            )),
            Dispatcher::FOUND => $this->handleFound($request),
            default           => throw new InvalidHttpDeclarationException(
                'Unsupported HTTP dispatch status.',
            ),
        };

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        return $this->responseConverter->convert($result);
    }

    /** @throws Throwable When listener selection or reporting an HTTP handler failure throws */
    private function handleRoutingFailure(ServerRequestInterface $request, HttpException $exception): ResponseInterface
    {
        $response = ResponseContext::get();
        $fallback = self::text($exception->getMessage(), $exception->statusCode, $exception->headers);

        $configuration = $request->getAttribute('super-kernel.server.listener');

        if (!is_array($configuration)
            || !is_string($configuration['name'] ?? null)
            || '' === $configuration['name']
        ) {
            throw new InvalidHttpDeclarationException('Routing failure dispatch requires a listener name.');
        }

        try {
            return $this->httpExceptionDispatcherFactory->forListener($configuration['name'])->dispatch(
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

    /**
     * @throws NotFoundExceptionInterface  When the route dispatcher or controller is not registered
     * @throws ContainerExceptionInterface When the route dispatcher or controller cannot be resolved
     */
    private function handleFound(ServerRequestInterface $request): mixed
    {
        return $this->dispatcher()->handle($request);
    }

    /**
     * @param array<non-empty-string, string|string[]> $headers
     */
    private static function text(string $body, int $status, array $headers = []): ResponseInterface
    {
        $response = ResponseContext::get()
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody(new StringStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** @throws ContainerExceptionInterface When the route dispatcher cannot be resolved */
    private function dispatcher(): RouteDispatcher
    {
        return $this->container->get(RouteDispatcher::class);
    }

}
