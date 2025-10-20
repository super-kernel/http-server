<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Contract\AttributeCollectorInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Contract\MiddlewareInterface;
use function sprintf;

final class MiddlewareManager
{
	private MiddlewareInterface $middleware;

	private ?ReflectionCollectorInterface $reflectionManager = null {
		get => $this->reflectionManager ??= $this->container->get(ReflectionCollectorInterface::class);
	}

	private ?AttributeCollectorInterface $attributeCollector = null {
		get => $this->attributeCollector ??= $this->container->get(AttributeCollectorInterface::class);
	}

	public function __construct(private readonly ContainerInterface $container)
	{
	}

	/**
	 * @return MiddlewareInterface
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function getMiddleware(): MiddlewareInterface
	{
		if (!isset($this->middleware)) {
			$this->middleware = new class extends SplPriorityQueue implements MiddlewareInterface {
			};

			$this->process(Middleware::class);
			$this->process(Middlewares::class);
		}

		return clone $this->middleware;
	}

	/**
	 * @param string $attributeClass
	 *
	 * @return void
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	private function process(string $attributeClass): void
	{
		foreach ($this->attributeCollector->getAttributes($attributeClass) as $class => $attributes) {

			/* @var Middleware|Middlewares $attribute */
			foreach ($attributes as $attribute) {
				$middlewares = is_subclass_of($attribute, Middleware::class)
					? [$attribute->middleware]
					: $attribute->middlewares;

				foreach ($middlewares as $middleware) {
					if (!is_subclass_of($middleware, MiddlewareInterface::class)) {
						throw new RuntimeException(
							sprintf('MiddlewareProvider must implement %s interface', PsrMiddlewareInterface::class),
						);
					}

					$this->middleware->insert($this->container->get($middleware->middleware), $middleware->priority);
				}
			}
		}
	}
}