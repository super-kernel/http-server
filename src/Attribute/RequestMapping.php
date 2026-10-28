<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Attribute;

use Attribute;
use InvalidArgumentException;

use function in_array;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class RequestMapping
{
    public const string GET = 'GET';

    public const string HEAD = 'HEAD';

    public const string POST = 'POST';

    public const string PUT = 'PUT';

    public const string PATCH = 'PATCH';

    public const string DELETE = 'DELETE';

    public const string CONNECT = 'CONNECT';

    public const string OPTIONS = 'OPTIONS';

    public const string TRACE = 'TRACE';

    private const array METHODS = [
        self::GET,
        self::HEAD,
        self::POST,
        self::PUT,
        self::PATCH,
        self::DELETE,
        self::CONNECT,
        self::OPTIONS,
        self::TRACE,
    ];

    /**
     * @param non-empty-string $method One of the HTTP method constants declared by this class
     *
     * @throws InvalidArgumentException When the method is not declared by this class
     */
    public function __construct(public string $path, public string $method = self::GET)
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(
                'HTTP request method must be one of GET, HEAD, POST, PUT, PATCH, DELETE, CONNECT, OPTIONS, or TRACE.',
            );
        }
    }
}
