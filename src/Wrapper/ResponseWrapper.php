<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Wrapper;

use Laminas\Diactoros\Response;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\StreamInterface;
use SuperKernel\HttpServer\Contract\ResponseInterface;
use SuperKernel\HttpServer\Message\SwooleStream;
use function json_encode;

final class ResponseWrapper implements ResponseInterface
{
	private PsrResponseInterface $response;

	private \Swoole\Http\Response $swooleResponse;

	public function __construct()
	{
		$this->response = new Response();
	}

	public function getProtocolVersion(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withProtocolVersion(string $version): MessageInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getHeaders(): array
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function hasHeader(string $name): bool
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getHeader(string $name): array
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getHeaderLine(string $name): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withHeader(string $name, $value): MessageInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withAddedHeader(string $name, $value): MessageInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withoutHeader(string $name): MessageInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getBody(): StreamInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withBody(StreamInterface $body): MessageInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getStatusCode(): int
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withStatus(int $code, string $reasonPhrase = ''): PsrResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getReasonPhrase(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getResponse(): PsrResponseInterface
	{
		return $this->response;
	}

	public function getSwooleResponse(): \Swoole\Http\Response
	{
		return $this->swooleResponse;
	}

	public function setSwooleResponse(\Swoole\Http\Response $response): ResponseInterface
	{
		$this->swooleResponse = $response;

		return $this;
	}

	public function __call(string $name, array $arguments): mixed
	{
		return $this->getResponse()->{$name}(...$arguments);
	}
}