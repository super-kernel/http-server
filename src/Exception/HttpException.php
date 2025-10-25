<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Exception;

use RuntimeException;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use Throwable;

class HttpException extends RuntimeException
{
	public readonly int $statusCode;

	public function __construct(int $statusCode, string $message = '', $code = 0, ?Throwable $previous = null)
	{
		$this->statusCode = $statusCode;

		$message = $message ?: new ResponseWrapper()->withStatus($statusCode)->getReasonPhrase();

		parent::__construct($message, $code, $previous);
	}

	public function getStatusCode(): int
	{
		return $this->statusCode;
	}
}