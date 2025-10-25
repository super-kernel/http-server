<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AttributeCollectorInterface;
use SuperKernel\HttpServer\Attribute\ExceptionHandler;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherInterface;
use SuperKernel\HttpServer\Contract\ExceptionHandlerInterface;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherFactoryInterface;
use SuperKernel\HttpServer\Dispatcher\HttpExceptionDispatcher;

#[Provider(ExceptionDispatcherFactoryInterface::class)]
final class ExceptionDispatcherFactory implements ExceptionDispatcherFactoryInterface
{
	/**
	 * @var array<string, SplPriorityQueue> $exceptions
	 */
	private array $exceptions = [];

	public function getDispatcher(string $serverName): ExceptionDispatcherInterface
	{
		return new HttpExceptionDispatcher($this->exceptions[$serverName] ?? new SplPriorityQueue);
	}

	/**
	 * @param ContainerInterface          $container
	 * @param AttributeCollectorInterface $attributeCollector
	 *
	 * @return ExceptionDispatcherFactory
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ContainerInterface          $container,
		AttributeCollectorInterface $attributeCollector,
	): ExceptionDispatcherFactory
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
}