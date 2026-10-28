<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Exception;

use LogicException;

final class InvalidHttpDeclarationException extends LogicException implements HttpServerExceptionInterface
{
}
