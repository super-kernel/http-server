<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use stdClass;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Request\SwooleRequestAdapter;
use Swoole\Http\Request;

use function fclose;
use function file_put_contents;
use function fopen;
use function method_exists;
use function sprintf;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

final class SwooleRequestAdapterTest extends TestCase
{
    /**
     * These assertions protect the public interface contract even when static types already imply it.
     *
     * @noinspection PhpConditionAlreadyCheckedInspection
     */
    public function testAdaptsNativeRequestToAPsrServerRequestWithoutRetainingIt(): void
    {
        $nativeRequest = Request::create();
        $rawRequest = "POST /widgets?id=42 HTTP/1.1\r\n"
            . "Host: example.test\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . "Cookie: session=abc\r\n"
            . "Content-Length: 9\r\n\r\n"
            . 'name=test';

        self::assertSame(strlen($rawRequest), $nativeRequest->parse($rawRequest));

        $request = new SwooleRequestAdapter()->adapt($nativeRequest);

        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertInstanceOf(ServerRequestInterface::class, $request);
        self::assertInstanceOf(ServerRequest::class, $request);
        self::assertFalse(method_exists($request, 'nativeRequest'));
        self::assertSame('POST', $request->getMethod());
        self::assertSame('http://example.test/widgets?id=42', (string) $request->getUri());
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame([
            'id' => '42',
        ], $request->getQueryParams());
        self::assertSame([
            'session' => 'abc',
        ], $request->getCookieParams());
        self::assertSame([
            'name' => 'test',
        ], $request->getParsedBody());
        self::assertSame('name=test', (string) $request->getBody());
        self::assertTrue($request->getBody()->isReadable());
        self::assertFalse($request->getBody()->isWritable());

        $modified = $request->withAttribute('route', 'widgets');

        self::assertInstanceOf(ServerRequest::class, $modified);
        self::assertNotSame($request, $modified);
        self::assertFalse(method_exists($modified, 'nativeRequest'));
        self::assertSame('widgets', $modified->getAttribute('route'));
    }

    public function testAdaptsNativeUploadMetadata(): void
    {
        $nativeRequest = new Request();
        $nativeRequest->server = [
            'request_method' => 'POST',
            'request_scheme' => 'https',
            'request_uri'    => '/upload',
        ];
        $nativeRequest->header = [
            'host' => 'example.test',
        ];
        $nativeRequest->files = [
            'document' => [
                'name'     => 'empty.txt',
                'type'     => 'text/plain',
                'tmp_name' => '',
                'error'    => UPLOAD_ERR_NO_FILE,
                'size'     => 0,
            ],
        ];

        $request = new SwooleRequestAdapter()->adapt($nativeRequest);
        $upload = $request->getUploadedFiles()['document'];

        self::assertSame('https://example.test/upload', (string) $request->getUri());
        self::assertSame('empty.txt', $upload->getClientFilename());
        self::assertSame('text/plain', $upload->getClientMediaType());
        self::assertSame(UPLOAD_ERR_NO_FILE, $upload->getError());
        self::assertSame(0, $upload->getSize());
    }

    public function testAdaptsNestedSuccessfulUploadMetadata(): void
    {
        $temporaryName = tempnam(sys_get_temp_dir(), 'super-kernel-upload-');
        self::assertNotFalse($temporaryName);
        self::assertSame(7, file_put_contents($temporaryName, 'payload'));

        try {
            $nativeRequest = new Request();
            $nativeRequest->server = [
                'request_method' => 'POST',
                'request_uri'    => '/upload',
            ];
            $nativeRequest->files = [
                'attachments' => [
                    'document' => [
                        'name'     => 'payload.txt',
                        'type'     => 'text/plain',
                        'tmp_name' => $temporaryName,
                        'error'    => UPLOAD_ERR_OK,
                        'size'     => 7,
                    ],
                ],
            ];

            $files = new SwooleRequestAdapter()->adapt($nativeRequest)->getUploadedFiles();
            $upload = $files['attachments']['document'];

            self::assertInstanceOf(UploadedFileInterface::class, $upload);
            self::assertSame('payload.txt', $upload->getClientFilename());
            self::assertSame('text/plain', $upload->getClientMediaType());
            self::assertSame(UPLOAD_ERR_OK, $upload->getError());
            self::assertSame(7, $upload->getSize());
            self::assertSame('payload', (string) $upload->getStream());
        } finally {
            @unlink($temporaryName);
        }
    }

    public function testRejectsNativeSwooleHandlesNestedInRequestMetadata(): void
    {
        foreach ([
            'server',
            'header',
            'cookie',
            'get',
            'files',
            'post',
        ] as $field) {
            $nativeRequest = new Request();
            $nativeRequest->server = [
                'request_method' => 'GET',
                'request_uri'    => '/',
            ];
            $metadata = [
                'nested' => [
                    'native' => $nativeRequest,
                ],
            ];

            if ('server' === $field) {
                $nativeRequest->server['nested'] = $metadata['nested'];
            } else {
                $nativeRequest->{$field} = $metadata;
            }

            try {
                new SwooleRequestAdapter()->adapt($nativeRequest);
                self::fail(sprintf('Expected native handle rejection for %s.', $field));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($field, $exception->getMessage());
                self::assertStringContainsString('native Swoole HTTP objects', $exception->getMessage());
            }
        }
    }

    public function testRejectsObjectContainersThatCouldHideNativeHandles(): void
    {
        $nativeRequest = new Request();
        $container = new stdClass();
        $container->nativeRequest = $nativeRequest;
        $nativeRequest->server = [
            'request_method' => 'GET',
            'request_uri'    => '/',
            'nested'         => [
                'container' => $container,
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('scalar, null, or nested array');

        new SwooleRequestAdapter()->adapt($nativeRequest);
    }

    public function testRejectsResourcesInRequestMetadata(): void
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        $nativeRequest = new Request();
        $nativeRequest->server = [
            'request_method' => 'GET',
            'request_uri'    => '/',
            'resource'       => $resource,
        ];

        try {
            new SwooleRequestAdapter()->adapt($nativeRequest);
            self::fail('Expected request metadata resource rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('resource', $exception->getMessage());
        } finally {
            fclose($resource);
        }
    }

    #[DataProvider('validAuthorities')]
    public function testAcceptsHostAuthorities(string $authority, string $host, ?int $port): void
    {
        $native = new Request();
        $native->header = [
            'host' => $authority,
        ];
        $native->server = [
            'request_uri'  => '/user',
            'query_string' => 'x=%23%3F',
        ];

        $request = new SwooleRequestAdapter()->adapt($native);

        self::assertSame($host, $request->getUri()->getHost());
        self::assertSame($port, $request->getUri()->getPort());
        self::assertSame('/user', $request->getUri()->getPath());
        self::assertSame('x=%23%3F', $request->getUri()->getQuery());
        self::assertSame($authority, $request->getHeaderLine('Host'));
        self::assertSame('', $request->getUri()->getFragment());
        self::assertSame('', $request->getUri()->getUserInfo());
    }

    /** @return iterable<string, array{string, string, ?int}> */
    public static function validAuthorities(): iterable
    {
        return [
            'domain' => [
                'example.test',
                'example.test',
                null,
            ],
            'case and trailing dot' => [
                'EXAMPLE.TEST.',
                'example.test.',
                null,
            ],
            'punycode' => [
                'xn--bcher-kva.test',
                'xn--bcher-kva.test',
                null,
            ],
            'reg-name characters' => [
                "a_~!$&'()*+,;=-.test",
                "a_~!$&'()*+,;=-.test",
                null,
            ],
            'percent encoded reg-name' => [
                'ex%61mple.test',
                'ex%61mple.test',
                null,
            ],
            'encoded delimiter remains host data' => [
                'ex%2Fample.test',
                'ex%2fample.test',
                null,
            ],
            'IPv4' => [
                '127.0.0.1',
                '127.0.0.1',
                null,
            ],
            'IPv4 with port' => [
                '127.0.0.1:8080',
                '127.0.0.1',
                8080,
            ],
            'IPv6' => [
                '[::1]',
                '[::1]',
                null,
            ],
            'IPv6 with port' => [
                '[2001:DB8::1]:8443',
                '[2001:db8::1]',
                8443,
            ],
            'IPv4 mapped IPv6' => [
                '[::ffff:192.0.2.1]:443',
                '[::ffff:192.0.2.1]',
                443,
            ],
            'zero port' => [
                'example.test:0',
                'example.test',
                0,
            ],
            'maximum port' => [
                'example.test:65535',
                'example.test',
                65535,
            ],
            'leading zeroes' => [
                'example.test:00000000000000000000000000000065535',
                'example.test',
                65535,
            ],
            'default port' => [
                'example.test:80',
                'example.test',
                null,
            ],
            'empty port' => [
                'example.test:',
                'example.test',
                null,
            ],
            'IPv6 empty port' => [
                '[::1]:',
                '[::1]',
                null,
            ],
        ];
    }

    #[DataProvider('invalidAuthorities')]
    public function testRejectsInvalidHostBeforeCreatingARequest(mixed $authority): void
    {
        $native = new Request();
        $native->header = [
            'host' => $authority,
        ];
        $native->server = [
            'request_uri' => '/index',
        ];

        try {
            new SwooleRequestAdapter()->adapt($native);
            self::fail('Expected a Bad Request exception.');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame('Bad Request', $exception->getMessage());
            self::assertSame([], $exception->headers);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidAuthorities(): iterable
    {
        foreach ([
            '',
            '/user',
            'example.test/user#',
            'example.test/index?',
            'example.test?x=',
            'example.test#fragment',
            'user@example.test',
            'example.test\\user',
            'example.test ',
            ' example.test',
            "example\ttest",
            "example\rtest",
            "example\ntest",
            "example\0test",
            "example\x01test",
            "example\x7ftest",
            'bücher.test',
            '::1',
            '2001:db8::1:8080',
            '[::1',
            '::1]',
            '[[::1]]',
            '[]',
            '[example.test]',
            '[:::]',
            '[::gg]',
            '[::1]suffix',
            '[::1]:-1',
            '[::1]:65536',
            ':80',
            'example.test:abc',
            'example.test:+80',
            'example.test:1.5',
            'example.test:65536',
            'example.test:99999999999999999999999',
            'example.test:80:90',
            'example.test:%38%30',
            'example%test',
            'example%2Gtest',
            '[fe80::1%25eth0]',
            '[v1.future]',
            null,
            false,
            80,
            [
                'example.test',
                'other.test',
            ],
            ] as $index => $authority) {
            yield (string) $index => [
                $authority,
            ];
        }
    }

    #[DataProvider('requestTargets')]
    public function testPreservesNativePathAndQueryBoundaries(string $target, string $path, string $query): void
    {
        $native = Request::create();
        $raw = "GET $target HTTP/1.1\r\nHost: example.test\r\n\r\n";
        self::assertSame(strlen($raw), $native->parse($raw));

        $uri = new SwooleRequestAdapter()->adapt($native)->getUri();

        self::assertSame('example.test', $uri->getHost());
        self::assertSame($path, $uri->getPath());
        self::assertSame($query, $uri->getQuery());
        self::assertSame('', $uri->getFragment());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function requestTargets(): iterable
    {
        return [
            'fragment delimiter is path data' => [
                '/a#b?x=1',
                '/a%23b',
                'x=1',
            ],
            'query delimiters' => [
                '/a?b?c#d',
                '/a',
                'b?c%23d',
            ],
            'encoded delimiters' => [
                '/%23/%3F?x=%23%3F',
                '/%23/%3F',
                'x=%23%3F',
            ],
            'double slash' => [
                '//other.test/user?x=1',
                '//other.test/user',
                'x=1',
            ],
            'absolute form stays native path data' => [
                'http://other.test/user?x=1',
                'http://other.test/user',
                'x=1',
            ],
            'leading query question mark' => [
                '/user??x=1',
                '/user',
                '?x=1',
            ],
            'empty query' => [
                '/user?',
                '/user',
                '',
            ],
        ];
    }

    public function testEncodesAQuestionMarkInsideTheNativePathWithoutReparsingIt(): void
    {
        $native = new Request();
        $native->header = [
            'host' => 'example.test',
        ];
        $native->server = [
            'request_uri'  => '/a?b#c',
            'query_string' => '?x=1#2',
        ];

        $uri = new SwooleRequestAdapter()->adapt($native)->getUri();

        self::assertSame('/a%3Fb%23c', $uri->getPath());
        self::assertSame('?x=1%232', $uri->getQuery());
        self::assertSame('', $uri->getFragment());
    }

    #[DataProvider('rawHeaderProtocols')]
    public function testReadsRawHeadersOnlyForHttp1(?string $protocol, bool $readsRawHeaders): void
    {
        $native = $this->createMock(Request::class);
        $native->header = [
            'host' => 'example.test',
        ];
        $native->server = [
            'server_protocol' => $protocol,
            'request_uri'     => '/user',
        ];

        if ($readsRawHeaders) {
            $native->expects($this->once())->method('getData')->willReturn(false);
        } else {
            $native->expects($this->never())->method('getData');
        }

        $request = new SwooleRequestAdapter()->adapt($native);

        self::assertSame('example.test', $request->getUri()->getHost());
        self::assertSame('/user', $request->getUri()->getPath());
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function rawHeaderProtocols(): iterable
    {
        yield 'HTTP/1.0' => [
            'HTTP/1.0',
            true,
        ];
        yield 'HTTP/1.1' => [
            'HTTP/1.1',
            true,
        ];
        yield 'HTTP/2' => [
            'HTTP/2',
            false,
        ];
        yield 'HTTP/2.0' => [
            'HTTP/2.0',
            false,
        ];
        yield 'HTTP/3' => [
            'HTTP/3',
            false,
        ];
        yield 'unrecognized HTTP/1 version' => [
            'HTTP/1.2',
            false,
        ];
        yield 'unspecified protocol' => [
            null,
            false,
        ];
    }

    public function testRejectsRepeatedHostFieldsEvenWhenSwooleOverwritesThem(): void
    {
        foreach ([
            'example.test',
            'other.test',
        ] as $second) {
            $native = Request::create();
            $raw = "GET /user HTTP/1.1\r\nHost: example.test\r\nhOsT: $second\r\n\r\n";
            self::assertSame(strlen($raw), $native->parse($raw));
            self::assertSame($second, $native->header['host']);

            try {
                new SwooleRequestAdapter()->adapt($native);
                self::fail('Expected repeated Host fields to be rejected.');
            } catch (HttpException $exception) {
                self::assertSame(400, $exception->statusCode);
            }
        }
    }

    public function testDoesNotTreatBodyTextAsHostFields(): void
    {
        $native = Request::create();
        $body = "\r\nHost: other.test\r\nHost: another.test\r\n";
        $raw = "POST /user HTTP/1.1\r\nHost: example.test\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        self::assertSame(strlen($raw), $native->parse($raw));

        $request = new SwooleRequestAdapter()->adapt($native);

        self::assertSame('example.test', $request->getUri()->getHost());
        self::assertSame($body, (string) $request->getBody());
    }

    public function testRejectsMissingHostInHttp11(): void
    {
        $native = Request::create();
        $raw = "GET /user HTTP/1.1\r\n\r\n";
        self::assertSame(strlen($raw), $native->parse($raw));

        try {
            new SwooleRequestAdapter()->adapt($native);
            self::fail('Expected HTTP/1.1 without Host to be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame('Bad Request', $exception->getMessage());
            self::assertSame([], $exception->headers);
        }
    }

    #[DataProvider('missingHostFallbackProtocols')]
    public function testPreservesMissingHostFallbackOrderAndSchemeSelection(?string $protocol): void
    {
        foreach ([
            [
                'server_name' => 'fallback.test:8080',
                'server_addr' => '127.0.0.1',
            ],
            [
                'server_addr' => '127.0.0.1',
            ],
            [],
        ] as $index => $server) {
            $native = new Request();
            $native->server = $server + [
                'https'           => 'on',
                'request_uri'     => '/user',
                'server_protocol' => $protocol,
            ];
            $uri = new SwooleRequestAdapter()->adapt($native)->getUri();

            self::assertSame([
                'fallback.test',
                '127.0.0.1',
                'localhost',
            ][$index], $uri->getHost());
            self::assertSame(0 === $index ? 8080 : null, $uri->getPort());
            self::assertSame('https', $uri->getScheme());
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function missingHostFallbackProtocols(): iterable
    {
        yield 'HTTP/1.0' => [
            'HTTP/1.0',
        ];
        yield 'HTTP/2 without authority' => [
            'HTTP/2',
        ];
        yield 'unspecified protocol' => [
            null,
        ];
    }
}
