<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

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
		return $this->serverRequest->getProtocolVersion();
	}

	public function withProtocolVersion(string $version): MessageInterface
	{
		return $this->serverRequest->withProtocolVersion($version);
	}

	public function getHeaders(): array
	{
		return $this->serverRequest->getHeaders();
	}

	public function hasHeader(string $name): bool
	{
		return $this->serverRequest->hasHeader($name);
	}

	public function getHeader(string $name): array
	{
		return $this->serverRequest->getHeader($name);
	}

	public function getHeaderLine(string $name): string
	{
		return $this->serverRequest->getHeaderLine($name);
	}

	public function withHeader(string $name, $value): MessageInterface
	{
		return $this->serverRequest->withHeader($name, $value);
	}

	public function withAddedHeader(string $name, $value): MessageInterface
	{
		return $this->serverRequest->withAddedHeader($name, $value);
	}

	public function withoutHeader(string $name): MessageInterface
	{
		return $this->serverRequest->withoutHeader($name);
	}

	public function getBody(): StreamInterface
	{
		return $this->serverRequest->getBody();
	}

	public function withBody(StreamInterface $body): MessageInterface
	{
		return $this->serverRequest->withBody($body);
	}

	public function getRequestTarget(): string
	{
		return $this->serverRequest->getRequestTarget();
	}

	public function withRequestTarget(string $requestTarget): RequestInterface
	{
		return $this->serverRequest->withRequestTarget($requestTarget);
	}

	public function getMethod(): string
	{
		return $this->serverRequest->getMethod();
	}

	public function withMethod(string $method): RequestInterface
	{
		return $this->serverRequest->withMethod($method);
	}

	public function getUri(): UriInterface
	{
		return $this->serverRequest->getUri();
	}

	public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
	{
		return $this->serverRequest->withUri($uri, $preserveHost);
	}

	public function __call(string $name, array $arguments): mixed
	{
		return call_user_func([$this->serverRequest, $name], ...func_get_args());
	}
}