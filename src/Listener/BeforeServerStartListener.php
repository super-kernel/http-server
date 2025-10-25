<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Listener;

use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\HttpServer\Factory\OnRequestEventFactory;
use SuperKernel\Server\Event\BeforeServerStart;
use SuperKernel\Server\ServerType;
use Swoole\Server;

#[
	Listener(BeforeServerStart::class),
]
final readonly class BeforeServerStartListener implements ListenerInterface
{
	public function __construct(private OnRequestEventFactory $onRequestEventFactory)
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

		$callback = $this->onRequestEventFactory->getEventCallback($event->config->name);

		match (true) {
			$event->server instanceof Server                        => $event->server->on('request', $callback),
			$event->server instanceof \Swoole\Coroutine\Http\Server => $event->server->handle('/', $callback),
		};
	}
}