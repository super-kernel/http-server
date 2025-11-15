<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SuperKernel\Di\Attribute\Provider;
use SuperKernel\HttpServer\Context\ResponseContext;
use SuperKernel\HttpServer\Contract\ResponseInterface as SuperKernelResponseInterface;

#[
	Provider(ResponseInterface::class),
	Provider(SuperKernelResponseInterface::class),
]
final readonly class Response implements SuperKernelResponseInterface
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

	public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getReasonPhrase(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function empty(): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function json(mixed $value, string $charset = 'utf-8', int $flags = 0, int $depth = 512): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function xml(mixed $data, string $charset = 'utf-8'): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function raw(mixed $data, string $charset = 'utf-8'): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function redirect(string $location, int $statusCode = 302): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function chunk(callable $callback): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getSwooleResponse(): \Swoole\Http\Response
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function setSwooleResponse(\Swoole\Http\Response $response): SuperKernelResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getResponse(): ResponseInterface
	{
		return ResponseContext::get();
	}

	public function __call(string $name, array $arguments): mixed
	{
		return $this->getResponse()->{$name}(...$arguments);
	}
}