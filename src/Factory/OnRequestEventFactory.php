<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SuperKernel\Di\Contract\ResolverFactoryInterface;
use SuperKernel\Di\Definition\ParameterDefinition;
use SuperKernel\HttpServer\CallbackEvent\OnRequestEvent;
use SuperKernel\HttpServer\RequestHandler;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class OnRequestEventFactory
{
    private ?ResolverFactoryInterface $resolverFactory = null {
        get => $this->resolverFactory ??= $this->container->get(ResolverFactoryInterface::class);
    }

    private ?RouteDispatcherFactory $routeDispatcherFactory = null {
        get => $this->routeDispatcherFactory ??= $this->container->get(RouteDispatcherFactory::class);
    }

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function getEventCallback(string $serverName): callable
    {
        $requestHandler = $this->getRequestHandler($serverName);

        $onRequestEvent = new OnRequestEvent($requestHandler);

        return fn(Request $request, Response $response) => $onRequestEvent->handle($request, $response);
    }

    private function getRequestHandler(string $serverName): RequestHandlerInterface
    {
        $routeDispatcher = $this->routeDispatcherFactory->getDispatcher($serverName);

        $parameterDefinition = new ParameterDefinition(
            classname: RequestHandler::class,
            methodName: '__construct',
            parameters: ['routeDispatcher' => $routeDispatcher],
        );

        $arguments = $this->resolverFactory->getResolver($parameterDefinition)->resolve($parameterDefinition);

        return new RequestHandler(...$arguments);
    }
}