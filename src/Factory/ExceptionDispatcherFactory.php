<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\HttpServer\Attribute\ExceptionHandler;
use SuperKernel\HttpServer\Contract\ExceptionDispatcherFactoryInterface;
use SuperKernel\HttpServer\Contract\ExceptionHandlerInterface;
use SuperKernel\HttpServer\ExceptionDispatcher;

#[Provider(ExceptionDispatcherFactoryInterface::class)]
final readonly class ExceptionDispatcherFactory implements ExceptionDispatcherFactoryInterface
{
	/**
	 * @var array<string, SplPriorityQueue> $exceptions
	 */
	private array $exceptions;

	/**
	 * @param ContainerInterface           $container
	 * @param AnnotationCollectorInterface $annotationCollector
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __construct(ContainerInterface $container, AnnotationCollectorInterface $annotationCollector)
	{
		foreach ($annotationCollector->getClassesByAttribute(ExceptionHandler::class) as $annotation) {
			$class = $annotation->getClass();

			/* @var ExceptionHandler $attributeInstance */
			$attributeInstance = $annotation->getInstance();

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

	public function getDispatcher(string $serverName): ExceptionDispatcher
	{
		return new ExceptionDispatcher($this->exceptions[$serverName] ?? new SplPriorityQueue);
	}
}