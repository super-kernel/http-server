<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Exception;

use RuntimeException;

final class RequestCallbackRegistrationException extends RuntimeException implements HttpServerExceptionInterface
{
}
