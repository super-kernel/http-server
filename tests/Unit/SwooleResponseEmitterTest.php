<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Unit;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\HttpMessage\Response;
use SuperKernel\HttpMessage\Stream\FileStream;
use SuperKernel\HttpMessage\Stream\StringStream;
use SuperKernel\HttpServer\Response\SwooleResponseEmitter;
use SuperKernel\HttpServer\Tests\Fixture\NullStdoutLogger;
use SuperKernel\HttpServer\Tests\Fixture\RecordingStdoutLogger;
use Swoole\Http\Response as SwooleResponse;

use function file_put_contents;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class SwooleResponseEmitterTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        $this->temporaryFiles = [];
    }

    public function testAcceptsOnlyThePsrAndSwooleResponses(): void
    {
        $constructor = new ReflectionMethod(SwooleResponseEmitter::class, '__construct');
        $parameters = new ReflectionMethod(SwooleResponseEmitter::class, 'emit')->getParameters();

        self::assertCount(1, $constructor->getParameters());
        self::assertSame(
            StdoutLoggerInterface::class,
            (string) $constructor->getParameters()[0]->getType(),
        );
        self::assertCount(2, $parameters);
        self::assertSame('response', $parameters[0]->getName());
        self::assertSame(ResponseInterface::class, (string) $parameters[0]->getType());
        self::assertSame('swooleResponse', $parameters[1]->getName());
        self::assertSame(SwooleResponse::class, (string) $parameters[1]->getType());
    }

    public function testEmitsStatusHeadersAndBody(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $emittedHeaders = [];
        $response = new Response(
            statusCode: 201,
            headers: [
                'Content-Type' => 'text/plain',
                'X-Trace'      => [
                    'one',
                    'two',
                ],
            ],
            body: 'created',
        );

        $target->expects(self::once())
            ->method('status')
            ->with(201, 'Created')
            ->willReturn(true);
        $target->expects(self::exactly(2))
            ->method('header')
            ->willReturnCallback(static function (
                string $name,
                array  $values,
                bool   $format,
            ) use (&$emittedHeaders): bool {
                self::assertFalse($format);
                $emittedHeaders[$name] = $values;

                return true;
            });
        $target->expects(self::once())
            ->method('end')
            ->with('created')
            ->willReturn(true);
        $target->expects(self::never())->method('write');
        $target->expects(self::never())->method('sendfile');

        $this->emitter()->emit($response, $target);

        self::assertSame([
            'Content-Type' => [
                'text/plain',
            ],
            'X-Trace' => [
                'one',
                'two',
            ],
        ], $emittedHeaders);
    }

    public function testEmitsTheResponseBodyWithoutInterpretingTheStatusCode(): void
    {
        foreach ([
            204,
            304,
        ] as $statusCode) {
            $target = $this->createMock(SwooleResponse::class);
            $target->method('status')->willReturn(true);
            $target->method('header')->willReturn(true);
            $target->expects(self::once())
                ->method('end')
                ->with('supplied')
                ->willReturn(true);

            $this->emitter()->emit(
                new Response(statusCode: $statusCode, body: 'supplied'),
                $target,
            );
        }
    }

    public function testRewindsASeekableBodyAndKeepsItsContentLength(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $body = new StringStream('complete');
        $emittedHeaders = [];
        self::assertSame('comp', $body->read(4));
        $target->method('status')->willReturn(true);
        $target->method('header')->willReturnCallback(static function (
            string $name,
            array  $values,
        ) use (&$emittedHeaders): bool {
            $emittedHeaders[$name] = $values;

            return true;
        });
        $target->expects(self::once())
            ->method('end')
            ->with('complete')
            ->willReturn(true);

        $this->emitter()->emit(new Response(headers: [
            'Content-Length' => '8',
        ], body: $body), $target);

        self::assertSame([
            'Content-Length' => [
                '8',
            ],
        ], $emittedHeaders);
    }

    public function testStreamsABodyOfUnknownSizeInChunksWithoutAContentLength(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $emittedHeaders = [];
        $written = [];
        $target->method('status')->willReturn(true);
        $target->method('header')->willReturnCallback(static function (
            string $name,
            array  $values,
        ) use (&$emittedHeaders): bool {
            $emittedHeaders[$name] = $values;

            return true;
        });
        $target->expects(self::exactly(2))
            ->method('write')
            ->willReturnCallback(static function (string $chunk) use (&$written): bool {
                $written[] = $chunk;

                return true;
            });
        $target->expects(self::once())
            ->method('end')
            ->willReturnCallback(static function (?string $content = null): bool {
                self::assertNull($content);

                return true;
            });
        $target->expects(self::never())->method('sendfile');

        $this->emitter()->emit(new Response(headers: [
            'Content-Type'   => 'text/event-stream',
            'Content-Length' => '11',
        ], body: $this->unsizedBody([
            'first',
            'second',
        ])), $target);

        self::assertSame([
            'Content-Type' => [
                'text/event-stream',
            ],
        ], $emittedHeaders);
        self::assertSame([
            'first',
            'second',
        ], $written);
    }

    public function testSendsARegularFileThroughSendfileWithoutAStaleContentLength(): void
    {
        $file = $this->temporaryFile('file body');
        $target = $this->createMock(SwooleResponse::class);
        $emittedHeaders = [];
        $target->method('status')->willReturn(true);
        $target->method('header')->willReturnCallback(static function (
            string $name,
            array  $values,
        ) use (&$emittedHeaders): bool {
            $emittedHeaders[$name] = $values;

            return true;
        });
        $target->expects(self::once())
            ->method('sendfile')
            ->with($file)
            ->willReturn(true);
        $target->expects(self::never())->method('write');
        $target->expects(self::never())->method('end');

        $this->emitter()->emit(new Response(headers: [
            'Content-Type'   => 'application/octet-stream',
            'Content-Length' => '999',
        ], body: new FileStream($file)), $target);

        self::assertSame([
            'Content-Type' => [
                'application/octet-stream',
            ],
        ], $emittedHeaders);
    }

    public function testFallsBackToChunkedWritesWhenSendfileFails(): void
    {
        $file = $this->temporaryFile('file body');
        $target = $this->createMock(SwooleResponse::class);
        $logger = new RecordingStdoutLogger();
        $target->method('status')->willReturn(true);
        $target->expects(self::once())->method('sendfile')->willReturn(false);
        $target->expects(self::once())->method('isWritable')->willReturn(true);
        $target->expects(self::once())
            ->method('write')
            ->with('file body')
            ->willReturn(true);
        $target->expects(self::once())->method('end')->willReturn(true);

        $this->emitter($logger)->emit(new Response(body: new FileStream($file)), $target);

        self::assertSame([
            [
                'level'   => 'error',
                'message' => sprintf('Unable to send the HTTP response file %s.', $file),
                'context' => [],
            ],
        ], $logger->records);
    }

    public function testStopsAfterAFailedSendfileWhenTheResponseIsNoLongerWritable(): void
    {
        $file = $this->temporaryFile('file body');
        $target = $this->createMock(SwooleResponse::class);
        $logger = new RecordingStdoutLogger();
        $target->method('status')->willReturn(true);
        $target->expects(self::once())->method('sendfile')->willReturn(false);
        $target->expects(self::once())->method('isWritable')->willReturn(false);
        $target->expects(self::never())->method('write');
        $target->expects(self::never())->method('end');

        $this->emitter($logger)->emit(new Response(body: new FileStream($file)), $target);

        self::assertCount(1, $logger->records);
        self::assertSame(sprintf('Unable to send the HTTP response file %s.', $file), $logger->records[0]['message']);
    }

    public function testLogsAFailedChunkWriteAndStops(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $logger = new RecordingStdoutLogger();
        $target->method('status')->willReturn(true);
        $target->expects(self::once())->method('write')->willReturn(false);
        $target->expects(self::never())->method('end');

        $this->emitter($logger)->emit(new Response(body: $this->unsizedBody([
            'first',
            'second',
        ])), $target);

        self::assertSame([
            [
                'level'   => 'error',
                'message' => 'Unable to write the HTTP response body.',
                'context' => [],
            ],
        ], $logger->records);
    }

    public function testLogsABodyReadFailureAfterWritingTheSwooleStatus(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $body = $this->createMock(StreamInterface::class);
        $failure = new RuntimeException('Body read failed.');
        $logger = new RecordingStdoutLogger();
        $body->method('getMetadata')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $body->expects(self::once())->method('read')->willThrowException($failure);
        $target->expects(self::once())
            ->method('status')
            ->with(200, 'OK')
            ->willReturn(true);
        $target->expects(self::never())->method('header');
        $target->expects(self::never())->method('write');
        $target->expects(self::never())->method('end');

        $this->emitter($logger)->emit(new Response(body: $body), $target);

        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('HTTP response emission failed.', $logger->records[0]['message']);
        self::assertSame($failure, $logger->records[0]['context']['exception']);
    }

    public function testLogsAFailedSwooleStatusWrite(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $logger = new RecordingStdoutLogger();
        $target->expects(self::once())->method('status')->willReturn(false);
        $target->expects(self::never())->method('header');
        $target->expects(self::never())->method('end');

        $this->emitter($logger)->emit(new Response(), $target);

        self::assertSame([
            [
                'level'   => 'error',
                'message' => 'Unable to emit HTTP status 200.',
                'context' => [],
            ],
        ], $logger->records);
    }

    public function testLogsAFailedSwooleHeaderWrite(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $logger = new RecordingStdoutLogger();
        $target->expects(self::once())->method('status')->willReturn(true);
        $target->expects(self::once())->method('header')->willReturn(false);
        $target->expects(self::never())->method('end');

        $this->emitter($logger)->emit(
            new Response(headers: [
                'X-Failure' => 'header',
            ]),
            $target,
        );

        self::assertSame([
            [
                'level'   => 'error',
                'message' => 'Unable to emit HTTP header X-Failure.',
                'context' => [],
            ],
        ], $logger->records);
    }

    public function testLogsAFailedSwooleTerminalWrite(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $logger = new RecordingStdoutLogger();
        $target->expects(self::once())->method('status')->willReturn(true);
        $target->expects(self::never())->method('header');
        $target->expects(self::once())->method('end')->willReturn(false);

        $this->emitter($logger)->emit(new Response(), $target);

        self::assertSame([
            [
                'level'   => 'error',
                'message' => 'Unable to finish the Swoole HTTP response.',
                'context' => [],
            ],
        ], $logger->records);
    }

    public function testLogsExceptionsThrownByTheSwooleResponse(): void
    {
        $swooleFailure = new RuntimeException('Swoole status failed.');
        $target = $this->createMock(SwooleResponse::class);
        $logger = new RecordingStdoutLogger();
        $target->expects(self::once())->method('status')->willThrowException($swooleFailure);
        $target->expects(self::never())->method('header');
        $target->expects(self::never())->method('end');

        $this->emitter($logger)->emit(new Response(), $target);

        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('HTTP response emission failed.', $logger->records[0]['message']);
        self::assertSame($swooleFailure, $logger->records[0]['context']['exception']);
    }

    public function testDoesNotPropagateAnExceptionThrownByTheLogger(): void
    {
        $target = $this->createMock(SwooleResponse::class);
        $logger = new class() extends AbstractLogger implements StdoutLoggerInterface {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('Logger failed.');
            }
        };
        $target->expects(self::once())->method('status')->willReturn(false);
        $target->expects(self::never())->method('header');
        $target->expects(self::never())->method('end');

        $this->emitter($logger)->emit(new Response(), $target);

        $this->addToAssertionCount(1);
    }

    private function emitter(?StdoutLoggerInterface $logger = null): SwooleResponseEmitter
    {
        return new SwooleResponseEmitter($logger ?? new NullStdoutLogger());
    }

    /**
     * A forward-only body of unknown size that serves one chunk per read, like an event stream.
     *
     * @param list<string> $chunks
     *
     * @return Stub&StreamInterface
     */
    private function unsizedBody(array $chunks): StreamInterface
    {
        $body = $this->createStub(StreamInterface::class);
        $eof = [];

        foreach ($chunks as $ignored) {
            $eof[] = false;
        }

        $eof[] = true;
        $body->method('isSeekable')->willReturn(false);
        $body->method('getSize')->willReturn(null);
        $body->method('getMetadata')->willReturn(null);
        $body->method('eof')->willReturnOnConsecutiveCalls(...$eof);
        $body->method('read')->willReturnOnConsecutiveCalls(...$chunks);

        return $body;
    }

    private function temporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'super-kernel-http-server-');

        if (false === $file || false === file_put_contents($file, $contents)) {
            throw new RuntimeException('Unable to create the HTTP Server test file.');
        }

        $this->temporaryFiles[] = $file;

        return $file;
    }
}
