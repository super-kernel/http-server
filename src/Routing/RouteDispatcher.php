<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Routing;

use FastRoute\Dispatcher;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpMessage\Context\RequestContext;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;

use function get_debug_type;
use function is_a;
use function is_object;
use function sprintf;

final readonly class RouteDispatcher
{
    public function __construct(
        private ContainerInterface         $container,
        private ControllerArgumentResolver $argumentResolver,
    )
    {
    }

    /**
     * @throws NotFoundExceptionInterface      When the controller or an action dependency is not registered
     * @throws ContainerExceptionInterface     When the controller or an action dependency cannot be resolved
     * @throws InvalidHttpDeclarationException When dispatch validation, action reflection or argument binding fails
     */
    public function handle(ServerRequestInterface $request): mixed
    {
        $dispatched = $request->getAttribute(Dispatched::class);

        if (!$dispatched instanceof Dispatched || Dispatcher::FOUND !== $dispatched->status) {
            throw new InvalidHttpDeclarationException(sprintf(
                '%s requires a found %s request attribute, %s provided.',
                self::class,
                Dispatched::class,
                get_debug_type($dispatched),
            ));
        }

        $route = $dispatched->handler();
        $request = $request->withAttribute(RouteDefinition::class, $route);

        foreach ($dispatched->variables as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        RequestContext::set($request);

        $controller = $this->controller($route);
        $arguments = $this->argumentResolver->resolve($route, $request, $dispatched->variables);
        return $controller->{$route->action}(...$arguments);
    }

    /**
     * @throws ContainerExceptionInterface     When the controller cannot be resolved
     * @throws InvalidHttpDeclarationException When the container entry is incompatible with the declared controller
     */
    private function controller(RouteDefinition $route): object
    {
        $className = $route->controller;
        $controller = $this->container->get($className);

        if (!is_object($controller) || !is_a($controller, $className)) {
            throw new InvalidHttpDeclarationException(sprintf(
                'HTTP controller %s must resolve to a compatible instance, %s returned.',
                $className,
                get_debug_type($controller),
            ));
        }

        return $controller;
    }
}
