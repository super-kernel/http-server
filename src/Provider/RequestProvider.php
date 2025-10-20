<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface as PsrRequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use SuperKernel\Attribute\Contract;
use SuperKernel\Attribute\Provider;
use SuperKernel\HttpServer\Context\RequestContext;
use SuperKernel\HttpServer\Contract\RequestInterface;

#[
	Contract(ServerRequestInterface::class),
	Provider(ServerRequestInterface::class),
]
final class RequestProvider implements RequestInterface
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

	public function getRequestTarget(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withRequestTarget(string $requestTarget): PsrRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getMethod(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withMethod(string $method): PsrRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getUri(): UriInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withUri(UriInterface $uri, bool $preserveHost = false): PsrRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getServerParams(): array
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getCookieParams(): array
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withCookieParams(array $cookies): ServerRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getQueryParams(): array
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withQueryParams(array $query): ServerRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getUploadedFiles(): array
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getParsedBody()
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withParsedBody($data): ServerRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getAttributes(): array
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getAttribute(string $name, $default = null)
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withAttribute(string $name, $value): ServerRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withoutAttribute(string $name): ServerRequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function __call(string $name, array $arguments): mixed
	{
		return call_user_func([
			                      RequestContext::get(),
			                      $name,
		                      ], ...$arguments);
	}
}