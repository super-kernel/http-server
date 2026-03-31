<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Config;

use SuperKernel\Attribute\Provider;
use SuperKernel\Server\Config;
use SuperKernel\Server\Constants\ModeConstants;
use SuperKernel\Server\Constants\TypeConstants;
use SuperKernel\Server\Contract\ServerConfigInterface;
use Swoole\Constant;

#[Provider(ServerConfigInterface::class)]
final class Server implements ServerConfigInterface
{
	public function getMode(): ModeConstants
	{
		return ModeConstants::SWOOLE_PROCESS;
	}

	public function getHookFlags(): int
	{
		return SWOOLE_HOOK_ALL;
	}

	public function getConfigs(): iterable
	{
		yield new Config(
			name    : 'http',
			type    : TypeConstants::SERVER_HTTP,
			host    : '127.0.0.1',
			port    : 9901,
			sockType: SWOOLE_SOCK_TCP,
			settings: [],
		);
	}

	public function getSettings(): array
	{
		return [
			Constant::OPTION_PID_FILE            => '/tmp/super-kernel.pid',
			Constant::OPTION_WORKER_NUM          => swoole_cpu_num(),
			Constant::OPTION_MAX_REQUEST         => 100000,
			Constant::OPTION_MAX_COROUTINE       => 100000,
			Constant::OPTION_ENABLE_COROUTINE    => false,
			Constant::OPTION_OPEN_TCP_NODELAY    => true,
			Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
			Constant::OPTION_SOCKET_BUFFER_SIZE  => 2 * 1024 * 1024,
			Constant::OPTION_BUFFER_OUTPUT_SIZE  => 2 * 1024 * 1024,
		];
	}
}