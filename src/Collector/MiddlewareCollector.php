<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Collector;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use ReflectionAttribute;
use RuntimeException;
use SuperKernel\Attribute\Factory;
use SuperKernel\Contract\AttributeCollectorInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;

#[
	Factory,
]
final class MiddlewareCollector
{
	private array $serverMiddlewares = [];

	private array $middlewares = [];

	private ?ReflectionCollectorInterface $reflectionCollector = null {
		get => $this->reflectionCollector ??= $this->container->get(ReflectionCollectorInterface::class);
	}

	private ?AttributeCollectorInterface $attributeCollector = null {
		get => $this->attributeCollector ??= $this->container->get(AttributeCollectorInterface::class);
	}

	public function __construct(private readonly ContainerInterface $container)
	{
	}

	public function getMiddlewares(string $serverName): array
	{
		return $this->serverMiddlewares[$serverName];
	}

	/**
	 * @param string $class
	 * @param string $method
	 *
	 * @return array
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function getScanMiddleware(string $class, string $method): array
	{
		$classMiddlewares = $this->scanMiddleware(
			$this->reflectionCollector->reflectClass($class)->getAttributes(Middlewares::class));
		$methodMiddlewares = $this->scanMiddleware(
			$this->reflectionCollector->reflectMethod($class, $method)->getAttributes(Middlewares::class));

		$middleware = [];

		foreach (array_merge($classMiddlewares, $methodMiddlewares) as $middlewares) {
			$middleware[] = $middlewares;
		}

		return $middleware;
	}

	/**
	 * @return $this
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(): MiddlewareCollector
	{
		/* @var array<Middleware> $attributes */
		foreach ($this->attributeCollector->getAttributes(Middleware::class) as $class => $attributes) {
			foreach ($attributes as $attribute) {
				$this->serverMiddlewares[$attribute->server][] = [
					$this->createInstance($class),
					$attribute->priority,
				];
			}
		}

		return $this;
	}

	/**
	 * @param array<ReflectionAttribute> $attributes
	 *
	 * @return array
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	private function scanMiddleware(array $attributes): array
	{
		$middleware = [];

		foreach ($attributes as $attribute) {
			/* @var Middlewares $attributeInstance */
			$attributeInstance = $attribute->newInstance();

			foreach ($attributeInstance->middlewares as $class => $priority) {
				$middleware[] = [
					$this->createInstance($class),
					$priority,
				];
			}
		}

		return $middleware;
	}

	/**
	 * @param string $class
	 *
	 * @return PsrMiddlewareInterface
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	private function createInstance(string $class): PsrMiddlewareInterface
	{
		if (!is_subclass_of($class, PsrMiddlewareInterface::class)) {
			throw new RuntimeException(
				sprintf('MiddlewareProvider must implement %s interface', PsrMiddlewareInterface::class),
			);
		}

		return $this->container->get($class);
	}
}