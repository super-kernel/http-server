<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Wrapper;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use SuperKernel\HttpServer\Contract\RequestInterface;
use SuperKernel\HttpServer\Message\SwooleStream;
use Swoole\Http\Request;

final readonly class RequestWrapper implements RequestInterface
{
	private ServerRequestInterface $serverRequest;

	public function __construct(private Request $swooleRequest)
	{
		$this->serverRequest = new ServerRequest(
			serverParams : $this->swooleRequest->server,
			uploadedFiles: $this->swooleRequest->files ?? [],
			uri          : new Uri($this->swooleRequest->server['request_uri']),
			method       : $this->swooleRequest->server['request_method'],
			body         : new SwooleStream(''),
			headers      : $this->swooleRequest->header,
			cookieParams : $this->swooleRequest->cookie ?? [],
			queryParams  : $this->swooleRequest->get ?? [],
			parsedBody   : $this->swooleRequest->post,
			protocol     : $this->swooleRequest->server['server_protocol'],
		);
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

	public function getRequestTarget(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withRequestTarget(string $requestTarget): RequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getMethod(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withMethod(string $method): RequestInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getUri(): UriInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
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

	public function getSwooleRequest(): Request
	{
		return $this->swooleRequest;
	}

	public function __call(string $name, array $arguments): mixed
	{
		return $this->serverRequest->{$name}(...$arguments);
	}

	public function getServerRequest(): ServerRequestInterface
	{
		return $this->serverRequest;
	}
}