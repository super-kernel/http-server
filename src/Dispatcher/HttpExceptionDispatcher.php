<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Dispatcher;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Attribute\Factory;
use SuperKernel\Contract\AttributeCollectorInterface;
use SuperKernel\HttpServer\Attribute\ExceptionHandler;
use SuperKernel\HttpServer\Context\ExceptionContext;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherInterface;
use SuperKernel\HttpServer\Contract\ExceptionHandlerInterface;
use SuperKernel\HttpServer\Exception\HttpException;
use SuperKernel\HttpServer\Message\SwooleStream;
use SuperKernel\HttpServer\Wrapper\ResponseWrapper;
use Throwable;

#[Factory]
final class HttpExceptionDispatcher implements ExceptionDispatcherInterface
{
	/**
	 * @var array<string, SplPriorityQueue> $exceptions
	 */
	private array $exceptions = [];

	public function __construct()
	{
	}

	/**
	 * @param ContainerInterface          $container
	 * @param AttributeCollectorInterface $attributeCollector
	 *
	 * @return HttpExceptionDispatcher
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ContainerInterface          $container,
		AttributeCollectorInterface $attributeCollector,
	): HttpExceptionDispatcher
	{
		foreach ($attributeCollector->getAttributes(ExceptionHandler::class) as $class => $attributes) {
			if (!is_subclass_of($class, ExceptionHandlerInterface::class)) {
				throw new RuntimeException(
					sprintf('The %s class must implement %s', $class, ExceptionHandlerInterface::class));
			}

			/* @var ExceptionHandler $attribute */
			$attribute = $attributes[0];

			if (!isset($this->exceptions[$attribute->server])) {
				$this->exceptions[$attribute->server] = new SplPriorityQueue();
			}

			$this->exceptions[$attribute->server]->insert($container->get($class), $attribute->priority);
		}

		return $this;
	}

	public function dispatcher(string $serverName, Throwable $throwable): ResponseInterface
	{
		ExceptionContext::set(clone $this->exceptions[$serverName]);

		return $this->handle($throwable, $this);
	}

	public function handle(Throwable $throwable, ExceptionDispatcherInterface $dispatcher): ResponseInterface
	{
		if (ExceptionContext::has() && !ExceptionContext::get()->isEmpty()) {
			return ExceptionContext::get()->extract()->handle($throwable, $this);
		}

		var_dump($throwable->getMessage());

		if ($throwable instanceof HttpException) {
			return new ResponseWrapper()->withStatus($throwable->getStatusCode())->withBody(
				new SwooleStream($throwable->getMessage()));
		}

		return new ResponseWrapper()->withStatus(400);
	}
}