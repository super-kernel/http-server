<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\Config;

use SuperKernel\Config\Attribute\Configuration;
use SuperKernel\Server\Mode;
use SuperKernel\Server\ServerConfig;
use SuperKernel\Server\ServerConfigInterface;
use SuperKernel\Server\ServerType;


#[Configuration]
final class Server implements ServerConfigInterface
{
	public function getMode(): Mode
	{
		return Mode::SWOOLE_PROCESS;
	}

	public function getServerConfigs(): array
	{
		return [
			new ServerConfig(
				name     : 'http',
				type     : ServerType::SERVER_HTTP,
				host     : '0.0.0.0',
				port     : 9501,
				sock_type: SWOOLE_SOCK_TCP,
			),
		];
	}
}