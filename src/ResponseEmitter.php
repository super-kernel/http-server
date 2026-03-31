<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
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

		$this->handleStream($response->getBody(), $swooleResponse);
	}

	private function handleStream(StreamInterface $stream, SwooleResponse $response): void
	{
		var_dump($stream->getContents());

		if ($stream->isReadable() && $response->isWritable()) {
			$response->end($stream->getContents());
		}
	}
}