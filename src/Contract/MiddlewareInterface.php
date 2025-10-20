<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Countable;
use Iterator;
use SplPriorityQueue;

/**
 * @mixin SplPriorityQueue
 */
interface MiddlewareInterface extends Iterator, Countable
{
}