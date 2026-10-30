<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Constants;

/**
 * Names of the PSR server-request attributes that HTTP Server writes and reads across its request pipeline.
 *
 * @internal
 */
enum RequestAttributeName: string
{
    /** Carries Server's original listener configuration array; routing selects by its `name` field. */
    case Listener = 'super-kernel.server.listener';
}
