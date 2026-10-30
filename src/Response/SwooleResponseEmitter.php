<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Response;

use Psr\Http\Message\ResponseInterface;
use SuperKernel\Contract\StdoutLoggerInterface;
use Swoole\Http\Response;
use Throwable;

use function is_file;
use function is_readable;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * Emits a PSR-7 response through a Swoole response: a regular file through sendfile(), a seekable body of known size
 * in one terminal write, and every other body as chunks written while they are read.
 */
final readonly class SwooleResponseEmitter
{
    private const int CHUNK_SIZE = 65_536;

    public function __construct(private StdoutLoggerInterface $logger)
    {
    }

    public function emit(ResponseInterface $response, Response $swooleResponse): void
    {
        try {
            $statusCode = $response->getStatusCode();

            if (!$swooleResponse->status($statusCode, $response->getReasonPhrase())) {
                EmissionFailureLog::write($this->logger, sprintf('Unable to emit HTTP status %d.', $statusCode));

                return;
            }

            $body = $response->getBody();
            $file = null;

            if ('plainfile' === $body->getMetadata('wrapper_type')) {
                $uri = $body->getMetadata('uri');

                if (is_string($uri) && '' !== $uri && is_file($uri) && is_readable($uri)) {
                    $file = $uri;
                }
            }

            $complete = null === $file && $body->isSeekable() && null !== $body->getSize();

            foreach ($response->getHeaders() as $name => $values) {
                // Swoole computes the length of a sent file itself and ignores the header for chunked bodies.
                if (!$complete && 'content-length' === strtolower($name)) {
                    continue;
                }

                if (!$swooleResponse->header($name, $values, false)) {
                    EmissionFailureLog::write($this->logger, sprintf('Unable to emit HTTP header %s.', $name));

                    return;
                }
            }

            if ($complete) {
                $body->rewind();

                if (!$swooleResponse->end($body->getContents())) {
                    EmissionFailureLog::write($this->logger, 'Unable to finish the Swoole HTTP response.');
                }

                return;
            }

            if (null !== $file) {
                if ($swooleResponse->sendfile($file)) {
                    return;
                }

                EmissionFailureLog::write($this->logger, sprintf('Unable to send the HTTP response file %s.', $file));

                if (!$swooleResponse->isWritable()) {
                    return;
                }
            }

            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_SIZE);

                if ('' === $chunk) {
                    break;
                }

                if (!$swooleResponse->write($chunk)) {
                    EmissionFailureLog::write($this->logger, 'Unable to write the HTTP response body.');

                    return;
                }
            }

            if (!$swooleResponse->end()) {
                EmissionFailureLog::write($this->logger, 'Unable to finish the Swoole HTTP response.');
            }
        } catch (Throwable $throwable) {
            EmissionFailureLog::write($this->logger, 'HTTP response emission failed.', $throwable);
        }
    }
}
