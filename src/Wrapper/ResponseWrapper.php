<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Wrapper;

use Laminas\Diactoros\Response;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SuperKernel\HttpServer\Context\RequestContext;
use SuperKernel\Stream\EmptyStream;
use SuperKernel\Stream\JsonStream;
use SuperKernel\Stream\StandardStream;
use SuperKernel\Stream\SwooleStream;

final class ResponseWrapper implements \SuperKernel\HttpServer\Contract\ResponseInterface
{
	private readonly ResponseInterface $response;

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

	public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getReasonPhrase(): string
	{
		return $this->__call(__FUNCTION__, func_get_args());
	}

	public function getResponse(): ResponseInterface
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

	public function json(mixed $value, string $charset = 'utf-8', int $flags = 0, int $depth = 512): ResponseInterface
	{
		return $this->getResponse()
			->withAddedHeader('content-type', 'application/json; charset=' . $charset)
			->withBody(new JsonStream($value, $flags, $depth));
	}

	public function xml(mixed $data, string $charset = 'utf-8'): ResponseInterface
	{
		return $this->getResponse()
			->withAddedHeader('content-type', 'application/xml; charset=' . $charset)
			->withBody(new JsonStream(...func_get_args()));
	}

	public function raw(mixed $data, string $charset = 'utf-8'): ResponseInterface
	{
		return $this->getResponse()
			->withAddedHeader('content-type', 'text/plain; charset=' . $charset)
			->withBody(new StandardStream($data));
	}

	public function redirect(string $location, int $statusCode = 302): ResponseInterface
	{
		$schema = RequestContext::get()->getUri()->getScheme();
		$host   = RequestContext::get()->getUri()->getAuthority();

		return $this->getResponse()
			->withStatus($statusCode)
			->withAddedHeader('Location', $schema . '://' . $host . '/' . ltrim($location, '/'))
			->withBody(new EmptyStream());
	}

	public function chunk(callable $callback): ResponseInterface
	{
		return $this->getResponse()
			->withBody(new SwooleStream(...func_get_args()));
	}
}