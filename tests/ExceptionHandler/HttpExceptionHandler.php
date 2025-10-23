<?php
declare(strict_types=1);

namespace SuperKernelTest\HttpServer\ExceptionHandler;


use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use SuperKernel\HttpServer\Attribute\ExceptionHandler;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherInterface;
use SuperKernel\HttpServer\Contract\ExceptionHandlerInterface;
use SuperKernel\HttpServer\Contract\ResponseInterface;
use SuperKernel\HttpServer\Exception\NotFoundHttpException;
use SuperKernel\HttpServer\Message\SwooleStream;
use Throwable;

#[ExceptionHandler(server: 'http')]
final readonly class HttpExceptionHandler implements ExceptionHandlerInterface
{
	public function __construct(private ResponseInterface $response)
	{
	}

	public function handle(Throwable $throwable, ExceptionDispatcherInterface $dispatcher): PsrResponseInterface
	{
		if ($throwable instanceof NotFoundHttpException) {
			return $this->response
				->withStatus(404)
				->withBody(
					new SwooleStream('Test Result: Not Found'),
				);
		}

		return $dispatcher->handle($throwable, $dispatcher);
	}
}