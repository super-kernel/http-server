<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Constants;

/**
 * Standard HTTP request methods accepted by route declarations, backed by their case-sensitive method tokens.
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Connect = 'CONNECT';
    case Options = 'OPTIONS';
    case Trace = 'TRACE';
}
