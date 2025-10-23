<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use Psr\Http\Message\ResponseInterface;

final readonly class ResponseEmitter
{
	public function emit(ResponseInterface $response, \Swoole\Http\Response $swooleResponse): void
	{
		$swooleResponse->status($response->getStatusCode(), $response->getReasonPhrase());

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

		$swooleResponse->end($response->getBody()->getContents());
	}
}