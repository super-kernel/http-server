<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SuperKernel\Stream\Contract\Stream\SwooleStreamInterface;
use Swoole\Http\Response as SwooleResponse;

final readonly class ResponseEmitter
{
	public function emit(ResponseInterface $response, SwooleResponse $swooleResponse): void
	{
		$swooleResponse->status($response->getStatusCode(), $response->getReasonPhrase());
		$swooleResponse->header('server', 'SuperKernel');

		foreach ($response->getHeaders() as $name => $values) {
			foreach ($values as $value) {
				$swooleResponse->header($name, $value);
			}
		}

		if (!empty($response->getHeader('Link')) && method_exists($swooleResponse, 'push')) {
			foreach ($response->getHeader('Link') ?? [] as $linkHeader) {
				preg_match_all('/<([^>]+)>;\s*rel=preload/', $linkHeader, $matches);
				foreach ($matches[1] ?? [] as $path) {
					$swooleResponse->push($path);
				}
			}
		}

		if (!empty($trailerHeader = $response->getHeaderLine('Trailer')) && method_exists($swooleResponse, 'trailer')) {
			foreach (explode(',', $trailerHeader) as $name) {
				$value = $response->getHeaderLine(trim($name));
				if ($value !== '') {
					$swooleResponse->trailer(trim($name), $value);
				}
			}
		}

		$this->bodyHandler($response->getBody(), $swooleResponse);
	}

	private function bodyHandler(StreamInterface $stream, SwooleResponse $response): void
	{
		match (true) {
			$stream instanceof SwooleStreamInterface => $this->handleSwooleStream(...func_get_args()),
			default                                  => $this->handleDefaultStream(...func_get_args()),
		};
	}

	private function handleDefaultStream(StreamInterface $stream, SwooleResponse $response): void
	{
		$response->end($stream->getContents());
	}

	private function handleSwooleStream(SwooleStreamInterface $stream, SwooleResponse $response): void
	{
		foreach ($stream->getContent() as $streamContent) {
			if ($stream->isReadable() && $response->isWritable()) {
				$response->write((string)$streamContent);
			} else {
				break;
			}
		}

		$stream->close();
		$response->end();
	}
}