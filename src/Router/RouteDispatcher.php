<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Router;

use FastRoute\Dispatcher\GroupCountBased;

final class RouteDispatcher extends GroupCountBased
{
	public function __construct($data, public string $serverName)
	{
		parent::__construct($data);
	}
}