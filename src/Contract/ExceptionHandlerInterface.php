<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Contract;

use Throwable;

interface ExceptionHandlerInterface
{
    public function handle(Throwable $throwable, ExceptionDispatcherInterface $dispatcher): \Psr\Http\Message\ResponseInterface;
}