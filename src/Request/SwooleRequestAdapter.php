<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Request;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use SuperKernel\HttpMessage\ServerRequest;
use SuperKernel\HttpMessage\Stream\ReadOnlyStream;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpMessage\Uri;
use SuperKernel\HttpServer\Constants\HttpMethod;
use SuperKernel\HttpServer\Exception\HttpException;
use Swoole\Http\Request;

use function array_key_exists;
use function is_string;
use function preg_match;
use function preg_match_all;
use function str_starts_with;
use function strcasecmp;
use function strpos;
use function strtoupper;
use function substr;

final class SwooleRequestAdapter
{
    /**
     * @throws InvalidArgumentException When request metadata contains objects, resources, or another unsupported value
     * @throws HttpException            When Host is invalid, repeated, or missing in HTTP/1.1
     */
    public function adapt(Request $request): ServerRequestInterface
    {
        $server = SwooleFieldSnapshot::copy($request->server ?? [], 'server');
        $headers = SwooleFieldSnapshot::copy($request->header ?? [], 'header');
        $nativeProtocol = $request->server['server_protocol'] ?? null;

        if ('HTTP/1.0' === $nativeProtocol || 'HTTP/1.1' === $nativeProtocol) {
            $data = $request->getData();

            if (false !== $data) {
                $end = strpos($data, "\r\n\r\n");

                // Swoole keeps only the last Host in header; count field lines in the raw header block, never the body.
                if (false !== $end && 1 < preg_match_all('/\r\nhost:/i', substr($data, 0, $end))) {
                    throw new HttpException(400, 'Bad Request');
                }
            }
        }

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

        if (1 !== preg_match(
            "/\A(\[[0-9A-Fa-f:.]+]|(?:[A-Za-z0-9._~!$&'()*+,;=-]|%[0-9A-Fa-f]{2})+)(?::([0-9]*))?\z/",
            (string) $authority,
            $matches,
        )) {
            throw new HttpException(400, 'Bad Request');
        }

        $port = $matches[2] ?? '';
        if ('' !== $port && 65535 < (int) $port) {
            throw new HttpException(400, 'Bad Request');
        }

        try {
            $uri = new Uri()->withHost($matches[1])->withPort('' === $port ? null : (int) $port);
        } catch (InvalidArgumentException) {
            throw new HttpException(400, 'Bad Request');
        }

        $path = (string) ($server['request_uri'] ?? '/');
        $queryString = (string) ($server['query_string'] ?? '');

        // withQuery removes one delimiter; prefix it so a leading question mark in the query remains data.
        $uri = $uri->withScheme($scheme)->withPath($path)->withQuery('?' . $queryString);
        $cookies = SwooleFieldSnapshot::copy($request->cookie ?? [], 'cookie');
        $query = SwooleFieldSnapshot::copy($request->get ?? [], 'get');
        $files = SwooleFieldSnapshot::copy($request->files ?? [], 'files');
        $parsedBody = null === $request->post ? null : SwooleFieldSnapshot::copy($request->post, 'post');
        $content = $request->rawContent();
        $protocol = (string) ($server['server_protocol'] ?? 'HTTP/1.1');

        if (str_starts_with($protocol, 'HTTP/')) {
            $protocol = substr($protocol, 5);
        }

        return new ServerRequest(
            method: strtoupper((string) ($server['request_method'] ?? HttpMethod::Get->value)),
            uri: $uri,
            serverParams: $server,
            headers: $headers,
            body: new ReadOnlyStream(new StringStream(false === $content ? '' : $content)),
            protocolVersion: $protocol,
            cookieParams: $cookies,
            queryParams: $query,
            uploadedFiles: UploadedFileNormalizer::normalize($files),
            parsedBody: $parsedBody,
        );
    }
}
