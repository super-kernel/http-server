<?php
declare(strict_types=1);

use SuperKernel\Contract\ApplicationInterface;
use SuperKernel\Di\Container;

require __DIR__ . '/../vendor/autoload.php';

new Container()->get(ApplicationInterface::class)->run();