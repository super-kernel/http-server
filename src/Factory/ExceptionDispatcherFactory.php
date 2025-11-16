<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Di\Attribute\Factory;
use SuperKernel\Di\Attribute\Provider;
use SuperKernel\Di\Contract\AttributeCollectorInterface;
use SuperKernel\HttpServer\Attribute\ExceptionHandler;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherFactoryInterface;
use SuperKernel\HttpServer\Contract\ExceptionHandlerInterface;
use SuperKernel\HttpServer\ExceptionDispatcher;

#[
	Provider(ExceptionDispatcherFactoryInterface::class),
	Factory,
]
final class ExceptionDispatcherFactory implements ExceptionDispatcherFactoryInterface
{
	/**
	 * @var array<string, SplPriorityQueue> $exceptions
	 */
	private array $exceptions = [];

	public function getDispatcher(string $serverName): ExceptionDispatcher
	{
		return new ExceptionDispatcher($this->exceptions[$serverName] ?? new SplPriorityQueue);
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
		foreach ($attributeCollector->getAttributes(ExceptionHandler::class) as $attribute) {
			$class = $attribute->class;

			/* @var ExceptionHandler $attributeInstance */
			$attributeInstance = $attribute->attribute;

			$serverName = $attributeInstance->server;

			if (!is_subclass_of($class, ExceptionHandlerInterface::class)) {
				throw new RuntimeException(
					sprintf('The %s class must implement %s', $class, ExceptionHandlerInterface::class));
			}

			if (!isset($this->exceptions[$serverName])) {
				$this->exceptions[$serverName] = new SplPriorityQueue();
			}

			$this->exceptions[$serverName]->insert($container->get($class), $attributeInstance->priority);
		}

		return $this;
	}
}