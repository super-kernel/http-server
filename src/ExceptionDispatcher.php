<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use SplPriorityQueue;
use SuperKernel\HttpServer\Exception\MethodNotAllowedHttpException;
use SuperKernel\HttpServer\Exception\NotFoundHttpException;
use SuperKernel\Stream\StandardStream;
use Throwable;

final class ExceptionDispatcher
{
	private SplPriorityQueue $exceptionHandler {
		get => clone $this->exceptionHandler;
	}

	public function __construct(SplPriorityQueue $exceptionHandler, private readonly LoggerInterface $logger)
	{
		$this->exceptionHandler = $exceptionHandler;
	}

	public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
	{
		try {
			foreach ($this->exceptionHandler as $handler) {
				$response = $handler->handle($throwable, $response);

				if ($handler->isStopPropagation()) {
					return $response;
				}
			}
		}
		catch (Throwable $throwable) {
			$this->logger->error($throwable->getMessage());
		}

		return match (true) {
			$throwable instanceof NotFoundHttpException         => $response->withStatus(404)->withBody(new StandardStream($throwable->getMessage())),
			$throwable instanceof MethodNotAllowedHttpException => $response->withStatus(400)->withBody(new StandardStream($throwable->getMessage())),
			default                                             => $response->withStatus(500)->withBody(new StandardStream('Internal server error')),
		};
	}
}