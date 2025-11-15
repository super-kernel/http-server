<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Router;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;
use SuperKernel\Di\Attribute\Factory;
use SuperKernel\Di\Collector\Attribute;
use SuperKernel\Di\Contract\AttributeCollectorInterface;
use SuperKernel\HttpServer\Attribute\Middleware;
use function sprintf;

#[
	Factory,
]
final class MiddlewareCollector
{
	private array $middlewares = [];

	public function getMiddlewares(string $serverName): array
	{
		return $this->middlewares[$serverName] ??= [];
	}

	/**
	 * @param ContainerInterface          $container
	 * @param AttributeCollectorInterface $attributeCollector
	 *
	 * @return $this
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ContainerInterface          $container,
		AttributeCollectorInterface $attributeCollector,
	): MiddlewareCollector
	{
		/* @var Attribute $attributes */
		foreach ($attributeCollector->getAttributes(Middleware::class) as $attribute) {

			$class = $attribute->class;

			/* @var Middleware $attributeInstance */
			$attributeInstance = $attribute->attribute;

			if (!is_subclass_of($class, MiddlewareInterface::class)) {
				throw new RuntimeException(
					sprintf('MiddlewareProvider must implement %s interface', MiddlewareInterface::class),
				);
			}

			$this->middlewares[$attributeInstance->server][] = [
				$container->get($class),
				$attributeInstance->priority,
			];
		}

		return $this;
	}
}