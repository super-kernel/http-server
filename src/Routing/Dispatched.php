<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Routing;

use FastRoute\Dispatcher;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;

use function in_array;

final readonly class Dispatched
{
    /**
     * @param array<string, string> $variables
     * @param list<string>          $allowedMethods
     */
    public function __construct(
        public int               $status,
        private ?RouteDefinition $handler = null,
        public array             $variables = [],
        public array             $allowedMethods = [],
    )
    {
        if (!in_array($status, [
            Dispatcher::NOT_FOUND,
            Dispatcher::FOUND,
            Dispatcher::METHOD_NOT_ALLOWED,
        ], true)) {
            throw new InvalidHttpDeclarationException('The HTTP route dispatcher returned an unsupported status.');
        }

        if (Dispatcher::FOUND === $status && null === $handler) {
            throw new InvalidHttpDeclarationException('A found HTTP route must provide its route handler.');
        }

        if (Dispatcher::FOUND !== $status && null !== $handler) {
            throw new InvalidHttpDeclarationException('Only a found HTTP route may provide a route handler.');
        }
    }

    public function handler(): RouteDefinition
    {
        return $this->handler ?? throw new InvalidHttpDeclarationException(
            'The dispatched HTTP route does not provide a route handler.',
        );
    }
}
