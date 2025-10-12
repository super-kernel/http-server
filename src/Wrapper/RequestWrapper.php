<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Wrapper;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use SuperKernel\HttpServer\Factory\ServerRequestFactory;
use Swoole\Http\Request;

final class RequestWrapper implements RequestInterface
{
	private ServerRequestInterface $serverRequest;

	public function __construct(Request $request)
	{
		$this->serverRequest = ServerRequestFactory::fromGlobals(
			server : $request->server,
			query  : $request->get,
			body   : $request->post,
			cookies: $request->cookie,
			files  : $request->files,
		);

		if (!empty($headers)) {
			foreach ($headers as $name => $value) {
				$this->serverRequest = $this->serverRequest->withHeader($name, $value);
			}
		}
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

	public function __call(string $name, array $arguments): mixed
	{
		return call_user_func([$this->serverRequest, $name], ...$arguments);
	}
}