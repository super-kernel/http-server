<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Exception;

use RuntimeException;

final class NotFoundHttpException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Not found.');
	}
}