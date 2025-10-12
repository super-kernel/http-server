<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Wrapper;

use Laminas\Diactoros\Response;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SuperKernel\Attribute\Contract;

#[Contract(ResponseInterface::class)]
final class ResponseWrapper implements ResponseInterface
{
	private ResponseInterface $response;

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

	public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getReasonPhrase(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function __call(string $name, array $arguments): mixed
	{
		return call_user_func([$this->response, $name], ...$arguments);
	}
}