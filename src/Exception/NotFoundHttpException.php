<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Exception;

final class NotFoundHttpException extends HttpException
{
	public function __construct()
	{
		parent::__construct(404);
	}
}