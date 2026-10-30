<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use Psr\Http\Message\ResponseInterface;
use SuperKernel\Contract\ExceptionDispatcherInterface;
use SuperKernel\ExceptionDispatcher\Attribute\ExceptionHandler;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerRegistration;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface;
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
        return HttpExceptionHandlerChain::dispatch($this->handlers, $this->exceptionDispatcher, $throwable, $response);
    }

    /**
     * Contains dispatch failures at the native request boundary; internal callers use dispatch() to propagate them.
     *
     * @return ResponseInterface An explicitly recovered response or a fresh, safe fallback for this request
     */
    public function recover(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        try {
            return HttpExceptionHandlerChain::dispatch(
                $this->handlers,
                $this->exceptionDispatcher,
                $throwable,
                $response,
            );
        } catch (Throwable $failure) {
            try {
                $this->exceptionDispatcher->dispatch($failure);
            } catch (Throwable) {
                // Reporting must not turn a request failure into a worker failure.
            }
        }

        try {
            return HttpExceptionFallback::response($throwable, new Response());
        } catch (Throwable $failure) {
            try {
                $this->exceptionDispatcher->dispatch($failure);
            } catch (Throwable) {
                // Reporting must not turn a request failure into a worker failure.
            }

            return PlainTextContent::apply(new Response(), 'Internal Server Error', 500);
        }
    }
}
