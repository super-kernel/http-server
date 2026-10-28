<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use SuperKernel\Contract\ExceptionDispatcherInterface;
use SuperKernel\ExceptionDispatcher\Registry\ExceptionHandlerProvider;
use SuperKernel\HttpServer\Attribute\HttpExceptionHandler;
use SuperKernel\HttpServer\Contract\HttpExceptionHandlerInterface;
use SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;

use function array_map;
use function get_debug_type;
use function sprintf;

final readonly class HttpExceptionDispatcherFactory
{
    /** @var array<non-empty-string, HttpExceptionDispatcher> */
    private array $dispatchersByName;

    private HttpExceptionDispatcher $unhandledDispatcher;

    public function __construct(
        ExceptionHandlerProvider     $handlerProvider,
        ExceptionDispatcherInterface $exceptionDispatcher,
    )
    {
        $handlersByName = [];

        foreach ($handlerProvider->getHandlersForAttribute(HttpExceptionHandler::class) as $registration) {
            if (!$registration->handler instanceof HttpExceptionHandlerInterface) {
                throw new InvalidHttpDeclarationException(sprintf(
                    'HTTP exception handler %s must implement %s; %s provided.',
                    $registration->handler::class,
                    HttpExceptionHandlerInterface::class,
                    get_debug_type($registration->handler),
                ));
            }

            /** @var HttpExceptionHandler $declaration */
            $declaration = $registration->declaration;
            $handlersByName[$declaration->name][] = $registration;
        }

        $this->dispatchersByName = array_map(
            fn(array $handlers): HttpExceptionDispatcher => new HttpExceptionDispatcher(
                $handlers,
                $exceptionDispatcher,
            ),
            $handlersByName,
        );
        $this->unhandledDispatcher = new HttpExceptionDispatcher([], $exceptionDispatcher);
    }

    /**
     * Reuses the immutable dispatcher for this listener, including an empty chain for unregistered names.
     *
     * @param non-empty-string $name
     */
    public function forListener(string $name): HttpExceptionDispatcher
    {
        return $this->dispatchersByName[$name] ?? $this->unhandledDispatcher;
    }

}
