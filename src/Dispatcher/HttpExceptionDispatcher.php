<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use Psr\Http\Message\ResponseInterface;
use SuperKernel\Contract\ExceptionDispatcherInterface;
use SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerRegistration;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Contract\StoppableHttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use Throwable;

use function get_debug_type;
use function sprintf;

final readonly class HttpExceptionDispatcher
{
    /**
     * @var list<array{
     *     registration: ExceptionHandlerRegistration<ExceptionHandler>,
     *     handler: HttpExceptionHandlerInterface,
     * }>
     */
    private array $handlers;

    /**
     * @param list<ExceptionHandlerRegistration<ExceptionHandler>> $handlers
     *
     * @throws InvalidHttpDeclarationException When a registration does not contain an HTTP handler
     */
    public function __construct(array $handlers, private ExceptionDispatcherInterface $exceptionDispatcher)
    {
        $registrations = [];

        foreach ($handlers as $registration) {
            if (!$registration instanceof ExceptionHandlerRegistration) {
                throw new InvalidHttpDeclarationException(
                    'HTTP exception handlers must be supplied as ExceptionHandlerRegistration values.',
                );
            }
            $handler = $registration->handler;

            if (!$handler instanceof HttpExceptionHandlerInterface) {
                throw new InvalidHttpDeclarationException(sprintf(
                    'HTTP exception handler must implement %s; %s provided.',
                    HttpExceptionHandlerInterface::class,
                    get_debug_type($handler),
                ));
            }

            $registrations[] = [
                'registration' => $registration,
                'handler'      => $handler,
            ];
        }

        $this->handlers = $registrations;
    }

    /** @throws Throwable When no handler stops propagation or reporting a handler failure throws */
    public function dispatch(
        Throwable         $throwable,
        ResponseInterface $response,
    ): ResponseInterface
    {
        $response = self::fallback($throwable, $response);

        foreach ($this->handlers as $entry) {
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
                $this->exceptionDispatcher->dispatch($handlerFailure);
            }
        }

        throw $throwable;
    }

    /**
     * Contains dispatch failures at the native request boundary; internal callers use dispatch() to propagate them.
     *
     * @return ResponseInterface An explicitly recovered response or a fresh, safe fallback for this request
     */
    public function recover(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        try {
            return $this->dispatch($throwable, $response);
        } catch (Throwable $failure) {
            $this->reportFailure($failure);
        }

        try {
            return self::fallback($throwable, new Response());
        } catch (Throwable $failure) {
            $this->reportFailure($failure);

            return self::text(new Response(), 'Internal Server Error', 500);
        }
    }

    private function reportFailure(Throwable $throwable): void
    {
        try {
            $this->exceptionDispatcher->dispatch($throwable);
        } catch (Throwable) {
            // Reporting must not turn a request failure into a worker failure.
        }
    }

    private static function fallback(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        if ($throwable instanceof HttpException) {
            return self::text(
                $response,
                $throwable->getMessage(),
                $throwable->statusCode,
                $throwable->headers,
            );
        }

        return self::text($response, 'Internal Server Error', 500);
    }

    /**
     * @param array<non-empty-string, string|string[]> $headers
     */
    private static function text(
        ResponseInterface $response,
        string            $body,
        int               $status,
        array             $headers = [],
    ): ResponseInterface
    {
        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');

        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody(new StringStream($body));
    }
}
