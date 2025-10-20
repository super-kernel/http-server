<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AttributeCollectorInterface;
use SuperKernel\Contract\ReflectionCollectorInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
use SuperKernel\HttpServer\Attribute\RequestMapping;

#[
	Provider(RouteCollector::class),
	Factory,
]
final readonly class RouteCollectorProvider
{
	public function __construct(private ContainerInterface $container)
	{
	}

	/**
	 * @param ReflectionCollectorInterface $reflectionCollector
	 * @param AttributeCollectorInterface  $attributeCollector
	 *
	 * @return RouteCollector
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ReflectionCollectorInterface $reflectionCollector,
		AttributeCollectorInterface  $attributeCollector,
	): RouteCollector
	{
		$routeCollector = new RouteCollector(new Std, new GroupCountBased);

		foreach ($attributeCollector->getAttributes(HttpController::class) as $class => $attributes) {
			/* @var HttpController $attribute */
			foreach ($attributes as $attribute) {
				$prefix         = $attribute->prefix;
				$reflectMethods = $reflectionCollector->reflectClass($class)->getMethods();

				foreach ($reflectMethods as $reflectMethod) {
					foreach ($reflectMethod->getAttributes(RequestMapping::class) as $methodAttribute) {
						/* @var RequestMapping $methodAttributeInstance */
						$methodAttributeInstance = $methodAttribute->newInstance();

						$path        = $methodAttributeInstance->path;
						$httpMethods = str_contains($methodAttributeInstance->methods, ',')
							? explode(',', $methodAttributeInstance->methods)
							: $methodAttributeInstance->methods;

						$handler = [
							$this->container->get($class),
							$reflectMethod->getName(),
						];

						$route = str_starts_with($path, '/') ? $path : $prefix . '/' . $path;

						$routeCollector->addRoute($httpMethods, $route, $handler);
					}
				}
			}
		}

		return $routeCollector;
	}
}