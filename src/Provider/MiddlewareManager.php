<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Contract\ReflectionManagerInterface;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Contract\MiddlewareInterface;
use function sprintf;

final class MiddlewareManager
{
	private MiddlewareInterface $middleware;

	private ?ReflectionManagerInterface $reflectionManager = null {
		get => $this->reflectionManager ??= $this->container->get(ReflectionManagerInterface::class);
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
	 * @param string $annotationClass
	 *
	 * @return void
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	private function process(string $annotationClass): void
	{
		$classes = $this->reflectionManager->getAttributes($annotationClass);

		foreach ($classes as $class) {
			$attributes = $this->reflectionManager->getClassAnnotations($class, $annotationClass);

			foreach ($attributes as $middlewareAttribute) {
				$instance = $middlewareAttribute->newInstance();

				$middlewares = $annotationClass === Middleware::class
					? [$instance->middleware]
					: $instance->middlewares;

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