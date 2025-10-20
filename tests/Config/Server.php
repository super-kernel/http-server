<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Config;

use SuperKernel\Attribute\Configuration;
use SuperKernel\Server\Mode;
use SuperKernel\Server\ServerConfig;
use SuperKernel\Server\ServerConfigInterface;
use SuperKernel\Server\ServerType;
use Swoole\Constant;


#[Configuration(ServerConfigInterface::class)]
final class Server implements ServerConfigInterface
{
	public function getMode(): Mode
	{
		return Mode::SWOOLE_PROCESS;
	}

	public function getServers(): array
	{
		return [
			new ServerConfig(
				name    : 'http',
				type    : ServerType::SERVER_HTTP,
				host    : '0.0.0.0',
				port    : 9501,
				sockType: SWOOLE_SOCK_TCP,
			),
			new ServerConfig(
				name    : 'http',
				type    : ServerType::SERVER_WEBSOCKET,
				host    : '0.0.0.0',
				port    : 9502,
				sockType: SWOOLE_SOCK_TCP,
			),
		];
	}

	public function getSettings(): array
	{
		return [
			Constant::OPTION_ENABLE_COROUTINE    => true,
			Constant::OPTION_WORKER_NUM          => swoole_cpu_num(),
			Constant::OPTION_PID_FILE            => '/tmp/runtime/super-kernel.pid',
			Constant::OPTION_OPEN_TCP_NODELAY    => true,
			Constant::OPTION_MAX_COROUTINE       => 100000,
			Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
			Constant::OPTION_MAX_REQUEST         => 100000,
			Constant::OPTION_SOCKET_BUFFER_SIZE  => 2 * 1024 * 1024,
			Constant::OPTION_BUFFER_OUTPUT_SIZE  => 2 * 1024 * 1024,
		];
	}
}