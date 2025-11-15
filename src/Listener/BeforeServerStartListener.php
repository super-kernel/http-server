<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Listener;

use SuperKernel\Contract\ListenerInterface;
use SuperKernel\EventDispatcher\Attribute\Listener;
use SuperKernel\HttpServer\Factory\OnRequestEventFactory;
use SuperKernel\Server\Event\BeforeServerStart;
use SuperKernel\Server\ServerType;
use Swoole\Coroutine\Http\Server;
use Swoole\Server as SwooleServer;

#[
	Listener(BeforeServerStart::class),
]
final readonly class BeforeServerStartListener implements ListenerInterface
{
	public function __construct(private OnRequestEventFactory $factory)
	{
	}

	/**
	 * @param object                  $event
	 *
	 * @psalm-param BeforeServerStart $event
	 *
	 * @return void
	 */
	public function process(object $event): void
	{
		if ($event->config->type !== ServerType::SERVER_HTTP) {
			return;
		}

		$callback = $this->factory->getEventCallback($event->config->name);

		match (true) {
			$event->server instanceof Server       => $event->server->handle('/', $callback),
			$event->server instanceof SwooleServer => $event->server->on('request', $callback),
		};
	}
}