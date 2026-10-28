<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Response;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
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
                $this->logFailure(sprintf('Unable to emit HTTP status %d.', $statusCode));

                return;
            }

            $body = $response->getBody();
            $file = self::regularFile($body);
            $complete = null === $file && $body->isSeekable() && null !== $body->getSize();

            foreach ($response->getHeaders() as $name => $values) {
                // Swoole computes the length of a sent file itself and ignores the header for chunked bodies.
                if (!$complete && 'content-length' === strtolower($name)) {
                    continue;
                }

                if (!$swooleResponse->header($name, $values, false)) {
                    $this->logFailure(sprintf('Unable to emit HTTP header %s.', $name));

                    return;
                }
            }

            if ($complete) {
                $body->rewind();

                if (!$swooleResponse->end($body->getContents())) {
                    $this->logFailure('Unable to finish the Swoole HTTP response.');
                }

                return;
            }

            if (null !== $file) {
                if ($swooleResponse->sendfile($file)) {
                    return;
                }

                $this->logFailure(sprintf('Unable to send the HTTP response file %s.', $file));

                if (!$swooleResponse->isWritable()) {
                    return;
                }
            }

            $this->writeChunks($body, $swooleResponse);
        } catch (Throwable $throwable) {
            $this->logFailure('HTTP response emission failed.', $throwable);
        }
    }

    private function writeChunks(StreamInterface $body, Response $swooleResponse): void
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK_SIZE);

            if ('' === $chunk) {
                break;
            }

            if (!$swooleResponse->write($chunk)) {
                $this->logFailure('Unable to write the HTTP response body.');

                return;
            }
        }

        if (!$swooleResponse->end()) {
            $this->logFailure('Unable to finish the Swoole HTTP response.');
        }
    }

    /**
     * @return non-empty-string|null The path of the readable regular file behind the body
     */
    private static function regularFile(StreamInterface $body): ?string
    {
        if ('plainfile' !== $body->getMetadata('wrapper_type')) {
            return null;
        }

        $uri = $body->getMetadata('uri');

        return is_string($uri) && '' !== $uri && is_file($uri) && is_readable($uri) ? $uri : null;
    }

    private function logFailure(string $message, ?Throwable $throwable = null): void
    {
        try {
            if (null === $throwable) {
                $this->logger->error($message);

                return;
            }

            $this->logger->error($message, [
                'exception' => $throwable,
            ]);
        } catch (Throwable) {
        }
    }
}
