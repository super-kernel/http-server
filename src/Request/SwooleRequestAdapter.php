<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Request;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpMessage\Stream\ReadOnlyStream;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpMessage\UploadedFile;
use SuperKernel\HttpMessage\Uri;
use SuperKernel\HttpServer\Exception\HttpException;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;

use function array_key_exists;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_starts_with;
use function strcasecmp;
use function strpos;
use function strtoupper;
use function substr;

use const UPLOAD_ERR_NO_FILE;

final class SwooleRequestAdapter
{
    /**
     * @throws InvalidArgumentException When request metadata contains objects, resources, or another unsupported value
     * @throws HttpException            When Host is invalid, repeated, or missing in HTTP/1.1
     */
    public function adapt(Request $request): ServerRequestInterface
    {
        $server = self::snapshot($request->server ?? [], 'server');
        $headers = self::snapshot($request->header ?? [], 'header');
        if (self::hasDuplicateHost($request)) {
            throw new HttpException(400, 'Bad Request');
        }
        $uri = self::uri($server, $headers);
        $cookies = self::snapshot($request->cookie ?? [], 'cookie');
        $query = self::snapshot($request->get ?? [], 'get');
        $files = self::snapshot($request->files ?? [], 'files');
        $parsedBody = null === $request->post ? null : self::snapshot($request->post, 'post');
        $content = $request->rawContent();
        $protocol = (string) ($server['server_protocol'] ?? 'HTTP/1.1');

        if (str_starts_with($protocol, 'HTTP/')) {
            $protocol = substr($protocol, 5);
        }

        return new ServerRequest(
            method: strtoupper((string) ($server['request_method'] ?? 'GET')),
            uri: $uri,
            serverParams: $server,
            headers: $headers,
            body: new ReadOnlyStream(new StringStream(false === $content ? '' : $content)),
            protocolVersion: $protocol,
            cookieParams: $cookies,
            queryParams: $query,
            uploadedFiles: self::uploadedFiles($files),
            parsedBody: $parsedBody,
        );
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function snapshot(array $values, string $field): array
    {
        $snapshot = [];

        foreach ($values as $key => $value) {
            $path = sprintf('%s[%s]', $field, $key);

            if ($value instanceof Request || $value instanceof SwooleResponse) {
                throw new InvalidArgumentException(sprintf(
                    'Swoole request field %s must not contain native Swoole HTTP objects.',
                    $path,
                ));
            }

            if (is_array($value)) {
                $snapshot[$key] = self::snapshot($value, $path);

                continue;
            }

            if (null !== $value && !is_scalar($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Swoole request field %s must contain only scalar, null, or nested array values; got %s.',
                    $path,
                    get_debug_type($value),
                ));
            }

            $snapshot[$key] = $value;
        }

        return $snapshot;
    }

    /**
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $headers
     */
    private static function uri(array $server, array $headers): UriInterface
    {
        if ('HTTP/1.1' === ($server['server_protocol'] ?? null) && !array_key_exists('host', $headers)) {
            throw new HttpException(400, 'Bad Request');
        }

        $scheme = (string) ($server['request_scheme'] ?? '');

        if ('' === $scheme) {
            $https = (string) ($server['https'] ?? '');
            $scheme = '' !== $https && 0 !== strcasecmp($https, 'off') ? 'https' : 'http';
        }

        $authority = $headers['host'] ?? $server['server_name'] ?? $server['server_addr'] ?? 'localhost';
        if (array_key_exists('host', $headers) && !is_string($headers['host'])) {
            throw new HttpException(400, 'Bad Request');
        }
        $uri = self::authority((string) $authority);
        $path = (string) ($server['request_uri'] ?? '/');
        $query = (string) ($server['query_string'] ?? '');

        // withQuery removes one delimiter; prefix it so a leading question mark in the query remains data.
        return $uri->withScheme($scheme)->withPath($path)->withQuery('?' . $query);
    }

    /** @throws HttpException When the authority cannot be represented as a supported URI host and port */
    private static function authority(string $authority): UriInterface
    {
        if (1 !== preg_match(
            "/\A(\[[0-9A-Fa-f:.]+]|(?:[A-Za-z0-9._~!$&'()*+,;=-]|%[0-9A-Fa-f]{2})+)(?::([0-9]*))?\z/",
            $authority,
            $matches,
        )) {
            throw new HttpException(400, 'Bad Request');
        }

        $port = $matches[2] ?? '';
        if ('' !== $port && 65535 < (int) $port) {
            throw new HttpException(400, 'Bad Request');
        }

        try {
            return new Uri()->withHost($matches[1])->withPort('' === $port ? null : (int) $port);
        } catch (InvalidArgumentException) {
            throw new HttpException(400, 'Bad Request');
        }
    }

    private static function hasDuplicateHost(Request $request): bool
    {
        $protocol = $request->server['server_protocol'] ?? null;
        if ('HTTP/1.0' !== $protocol && 'HTTP/1.1' !== $protocol) {
            return false;
        }

        $data = $request->getData();
        if (false === $data) {
            return false;
        }
        $end = strpos($data, "\r\n\r\n");

        // Swoole keeps only the last Host in header; count field lines in the raw header block, never the body.
        return false !== $end && 1 < preg_match_all('/\r\nhost:/i', substr($data, 0, $end));
    }

    /**
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, UploadedFileInterface|array>
     */
    private static function uploadedFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $name => $file) {
            if (!is_array($file)) {
                continue;
            }

            if (!array_key_exists('error', $file)) {
                $normalized[$name] = self::uploadedFiles($file);

                continue;
            }

            $error = $file['error'];
            $error = is_int($error) ? $error : UPLOAD_ERR_NO_FILE;
            $temporaryName = $file['tmp_name'] ?? '';

            $normalized[$name] = new UploadedFile(
                is_string($temporaryName) ? $temporaryName : '',
                is_int($file['size'] ?? null) ? $file['size'] : null,
                $error,
                is_string($file['name'] ?? null) ? $file['name'] : null,
                is_string($file['type'] ?? null) ? $file['type'] : null,
            );
        }

        return $normalized;
    }
}
