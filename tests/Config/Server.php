<?php
declare(strict_types=1);

namespace SuperKernelTest\Config;

use SuperKernel\Attribute\Contract;
use SuperKernel\Server\Config;
use SuperKernel\Server\ConfigInterface;
use SuperKernel\Server\Mode;
use SuperKernel\Server\ServerConfig;
use SuperKernel\Server\ServerType;


#[
	Contract(ConfigInterface::class),
]
final class Server implements ConfigInterface
{
	public function getMode(): Mode
	{
		return Mode::SWOOLE_PROCESS;
	}

	public function getServerConfigs(): ServerConfig
	{
		return new ServerConfig(
			new Config(
				name     : 'http',
				type     : ServerType::SERVER_HTTP,
				host     : '0.0.0.0',
				port     : 9501,
				sock_type: SWOOLE_SOCK_TCP,
			),
		);
	}
}