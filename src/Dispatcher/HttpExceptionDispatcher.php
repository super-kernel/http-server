<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use Psr\Http\Message\ResponseInterface;
use SplPriorityQueue;
use SuperKernel\HttpServer\Context\ExceptionContext;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherInterface;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Message\SwooleStream;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use Throwable;

final readonly class HttpExceptionDispatcher implements ExceptionDispatcherInterface
{
	public function __construct(private SplPriorityQueue $exceptions)
	{
	}

	public function dispatcher(Throwable $throwable): ResponseInterface
	{
		ExceptionContext::set(clone $this->exceptions);

		return $this->handle($throwable, $this);
	}

	public function handle(Throwable $throwable, ExceptionDispatcherInterface $dispatcher): ResponseInterface
	{
		if (ExceptionContext::has() && !ExceptionContext::get()->isEmpty()) {
			return ExceptionContext::get()->extract()->handle($throwable, $this);
		}

		if ($throwable instanceof HttpException) {
			return new ResponseWrapper()->withStatus($throwable->getStatusCode())->withBody(
				new SwooleStream($throwable->getMessage()));
		}

		return new ResponseWrapper()->withStatus(400);
	}
}