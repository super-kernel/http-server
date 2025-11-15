<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\ExceptionHandler;

use Psr\Http\Message\ResponseInterface;
use SuperKernel\HttpServer\Attribute\ExceptionHandler;
use SuperKernel\HttpServer\Contract\ExceptionHandlerInterface;
use SuperKernel\HttpServer\Exception\NotFoundHttpException;
use SuperKernel\Stream\StandardStream;
use SuperKernel\Stream\SwooleStream;
use Throwable;

#[ExceptionHandler(server: 'http')]
final readonly class HttpExceptionHandler implements ExceptionHandlerInterface
{
	public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
	{
		if ($throwable instanceof NotFoundHttpException) {
			return $response
				->withStatus(404)
				->withBody(
					new SwooleStream('Test Result: Not Found'),
				);
		}

		return $response;
	}

	public function isStopPropagation(): bool
	{
		return true;
	}
}