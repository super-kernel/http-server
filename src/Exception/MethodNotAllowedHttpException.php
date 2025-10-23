<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Exception;

final class MethodNotAllowedHttpException extends HttpException
{
	public function __construct(string $message)
	{
		parent::__construct(403, $message);
	}
}