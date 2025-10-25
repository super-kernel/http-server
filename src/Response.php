<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\StreamInterface;
use SuperKernel\Attribute\Provider;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\Contract\ResponseInterface;

#[
	Provider(ResponseInterface::class),
	Provider(PsrResponseInterface::class),
]
final readonly class Response implements ResponseInterface
{
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

	public function getSwooleResponse(): \Swoole\Http\Response
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function setSwooleResponse(\Swoole\Http\Response $response): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getResponse(): PsrResponseInterface
	{
		return ResponseContext::get();
	}

	public function __call(string $name, array $arguments): mixed
	{
		return $this->getResponse()->{$name}(...$arguments);
	}
}