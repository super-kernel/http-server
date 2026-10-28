<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Listener;

use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\HttpServer\Callbacks\OnRequestCallback;
use SuperKernel\HttpServer\Exception\RequestCallbackRegistrationException;
use SuperKernel\Server\Enum\Protocol;
use SuperKernel\Server\Event\AfterServerCreated;
use Swoole\Constant;
use Swoole\Server;
use Swoole\Server\Port as ServerPort;
use Throwable;

use function sprintf;
use function SuperKernel\Support\make;

#[Listener]
final readonly class BeforeServerStartListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            AfterServerCreated::class,
        ];
    }

    /** @psalm-param AfterServerCreated $event */
    public function process(object $event): void
    {
        $server = $event->server();
        $configuration = $event->configuration();
        $protocol = $configuration['protocol'] ?? Protocol::Raw;

        if (Protocol::Http !== $protocol && Protocol::WebSocket !== $protocol) {
            return;
        }

        if ($server instanceof Server || $server instanceof ServerPort) {
            $message = sprintf(
                'Unable to claim the HTTP request callback for the configured Swoole service "%s".',
                $configuration['name'],
            );

            try {
                $registered = null === $server->getCallback(Constant::EVENT_REQUEST)
                    && $server->on(
                        Constant::EVENT_REQUEST,
                        make(OnRequestCallback::class, [
                            'configuration' => $configuration,
                        ]),
                    );
            } catch (Throwable $throwable) {
                throw new RequestCallbackRegistrationException($message, 0, $throwable);
            }

            if (!$registered) {
                throw new RequestCallbackRegistrationException($message);
            }
        }
    }
}
