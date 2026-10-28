<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Stringable;
use SuperKernel\HttpMessage\Context\ResponseContext;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpServer\Exception\InvalidHttpDeclarationException;
use SuperKernel\HttpServer\Response\ResponseConverter;
use Throwable;

final class ResponseConverterTest extends TestCase
{
    protected function tearDown(): void
    {
        ResponseContext::clear();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function results(): iterable
    {
        yield 'array' => [
            [
                'ok' => true,
            ],
            'application/json; charset=utf-8',
            '{"ok":true}',
        ];
        yield 'string' => [
            'hello',
            'text/plain; charset=utf-8',
            'hello',
        ];
        yield 'integer' => [
            42,
            'text/plain; charset=utf-8',
            '42',
        ];
        yield 'float' => [
            1.5,
            'text/plain; charset=utf-8',
            '1.5',
        ];
        yield 'boolean' => [
            true,
            'text/plain; charset=utf-8',
            '1',
        ];
        yield 'stringable' => [
            new class() implements Stringable {
                public function __toString(): string
                {
                    return 'stringable';
                }
            },
            'text/plain; charset=utf-8',
            'stringable',
        ];
        yield 'JSON serializable' => [
            new class() implements JsonSerializable {
                /** @return array{ok: bool} */
                public function jsonSerialize(): array
                {
                    return [
                        'ok' => true,
                    ];
                }
            },
            'application/json; charset=utf-8',
            '{"ok":true}',
        ];
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('results')]
    public function testConvertsSupportedResults(
        mixed  $result,
        string $contentType,
        string $body,
    ): void
    {
        $context = new Response(statusCode: 202, headers: [
            'Content-Length' => '999',
            'X-Context'      => 'preserved',
        ]);
        ResponseContext::set($context);

        $response = new ResponseConverter()->convert($result);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('preserved', $response->getHeaderLine('X-Context'));
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame($contentType, $response->getHeaderLine('Content-Type'));
        self::assertSame($body, (string) $response->getBody());
        self::assertSame($context, ResponseContext::get());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPassesAResponseThrough(): void
    {
        $response = new Response(statusCode: 202);

        self::assertSame($response, new ResponseConverter()->convert($response));
        self::assertFalse(ResponseContext::has());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testReturnsTheCurrentResponseForNull(): void
    {
        $response = new Response(statusCode: 204);
        ResponseContext::set($response);

        self::assertSame($response, new ResponseConverter()->convert(null));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsUnsupportedResults(): void
    {
        $this->expectException(InvalidHttpDeclarationException::class);

        new ResponseConverter()->convert(new stdClass());
    }

    public function testDeclaresOnlyTheConvertMethod(): void
    {
        $methods = new ReflectionClass(ResponseConverter::class)->getMethods();

        self::assertCount(1, $methods);
        self::assertSame('convert', $methods[0]->getName());
    }
}
