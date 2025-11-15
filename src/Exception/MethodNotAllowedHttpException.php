<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Exception;

use RuntimeException;

final class MethodNotAllowedHttpException extends RuntimeException
{
	public function __construct(string $message)
	{
		parent::__construct($message);
	}
}