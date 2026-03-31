<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\MiddlewareInterface;
use RuntimeException;
use SplPriorityQueue;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AnnotationInterface;
use SuperKernel\HttpServer\Attribute\Middleware;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Collector\MiddlewareCollector;
use SuperKernel\HttpServer\Contract\MiddlewareCollectorInterface;
use function is_subclass_of;

#[
	Provider(MiddlewareCollectorInterface::class),
	Factory,
]
final class MiddlewareCollectorProvider
{
	/**
	 * @param ContainerInterface           $container
	 * @param AnnotationCollectorInterface $annotationCollector
	 *
	 * @return MiddlewareCollectorInterface
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ContainerInterface           $container,
		AnnotationCollectorInterface $annotationCollector,
	): MiddlewareCollectorInterface
	{
		$serverMiddlewares = [];
		foreach ($annotationCollector->getClassesByAttribute(Middleware::class) as $annotation) {
			$class = $annotation->getClass();

			if (!is_subclass_of($class, MiddlewareInterface::class)) {
				throw new RuntimeException(
					sprintf('Middleware must implement %s interface', MiddlewareInterface::class),
				);
			}

			/* @var Middleware $middleware */
			$middleware = $annotation->getInstance();

			if (!isset($serverMiddlewares[$middleware->server])) {
				$serverMiddlewares[$middleware->server] = new SplPriorityQueue();
			}

			$serverMiddlewares[$middleware->server]->insert($container->get($class), $middleware->priority);
		}

		$controllerMiddlewares = [];
		$actionMiddlewares = [];
		foreach ($annotationCollector->getClassesByAttribute(Middlewares::class) as $annotation) {
			/* @var Middlewares $middlewares */
			$middlewares = $annotation->getInstance();

			foreach ($middlewares->middlewares as $middleware => $priority) {
				if (!is_subclass_of($middleware, MiddlewareInterface::class)) {
					throw new RuntimeException(
						sprintf('Middleware must implement %s interface', MiddlewareInterface::class),
					);
				}


				if ($annotation->compatible(AnnotationInterface::TARGET_CLASS)) {
					$controller = $annotation->getClass();
					if (!isset($controllerMiddlewares[$controller])) {
						$controllerMiddlewares[$controller] = new SplPriorityQueue();
					}
					$controllerMiddlewares[$controller]->insert($container->get($middleware), $priority);
				}

				if ($annotation->compatible(AnnotationInterface::TARGET_METHOD)) {
					$controller = $annotation->getClass();
					$action = $annotation->getMethod();
					if (!isset($actionMiddlewares[$controller])) {
						$actionMiddlewares[$controller][$action] = new SplPriorityQueue();
					}
					$actionMiddlewares[$controller][$action]->insert($container->get($middleware), $priority);
				}

			}

		}

		return new MiddlewareCollector($serverMiddlewares, $controllerMiddlewares, $actionMiddlewares);
	}
}