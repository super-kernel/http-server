<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Listener;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\HttpServer\CallbackEvent\OnRequestEvent;
use SuperKernel\HttpServer\Collector\RouteCollector;
use SuperKernel\Server\Event\BeforeServerStart;
use SuperKernel\Server\ServerType;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;

#[
	Listener(BeforeServerStart::class),
]
final  class BeforeServerStartListener implements ListenerInterface
{
	private ?RouteCollector $routeCollector = null {
		get => $this->routeCollector ??= $this->container->get(RouteCollector::class);
	}

	/**
	 * @param ContainerInterface $container
	 */
	public function __construct(private readonly ContainerInterface $container)
	{
	}

	/**
	 * @param object                  $event
	 *
	 * @psalm-param BeforeServerStart $event
	 *
	 * @return void
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function process(object $event): void
	{
		if ($event->config->type !== ServerType::SERVER_HTTP) {
			return;
		}

		$serverName = $event->config->name;

		match (true) {
			$event->server instanceof Server                        => $event->server->on('request', $this->getRequestEventCallback($serverName)),
			$event->server instanceof \Swoole\Coroutine\Http\Server => $event->server->handle('/', $this->getRequestEventCallback($serverName)),
		};
	}

	/**
	 * @param string $serverName
	 *
	 * @return callable
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	private function getRequestEventCallback(string $serverName): callable
	{
		/* @var OnRequestEvent $onRequestEvent */
		$onRequestEvent = clone $this->container->get(OnRequestEvent::class);

		$onRequestEvent->setServerName($serverName);

		return fn(Request $request, Response $response) => $onRequestEvent($request, $response);
	}
}