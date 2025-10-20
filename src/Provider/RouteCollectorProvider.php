<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionMethod;
use SuperKernel\Attribute\Contract;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\ReflectionManagerInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Enumeration\Method;

#[
	Contract(RouteCollector::class),
	Provider(RouteCollector::class),
	Factory,
]
final readonly class RouteCollectorProvider
{
	public function __construct(private ContainerInterface $container)
	{
	}

	/**
	 * @param ReflectionManagerInterface $reflectionManager
	 *
	 * @return RouteCollector
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(ReflectionManagerInterface $reflectionManager): RouteCollector
	{
		$classes        = $reflectionManager->getAttributes(HttpController::class);
		$routeCollector = new RouteCollector(new Std, new GroupCountBased);

		foreach ($classes as $class) {
			$attributes = $reflectionManager->getClassAnnotations($class, HttpController::class);

			foreach ($attributes as $attribute) {
				/* @var HttpController $attributeInstance */
				$attributeInstance      = $attribute->newInstance();
				$prefix                 = $attributeInstance->prefix;
				$reflectionClassMethods = $reflectionManager->reflectClass($class)->getMethods(ReflectionMethod::IS_PUBLIC);

				foreach ($reflectionClassMethods as $reflectionClassMethod) {
					foreach ($reflectionClassMethod->getAttributes(RequestMapping::class) as $methodAttribute) {
						/**
						 * @var RequestMapping $methodAttributeInstance
						 */
						$methodAttributeInstance = $methodAttribute->newInstance();
						$path                    = $methodAttributeInstance->path;
						$httpMethods             = $methodAttributeInstance->methods instanceof Method
							? [$methodAttributeInstance->methods]
							: $methodAttributeInstance->methods;

						foreach ($httpMethods as $httpMethod) {
							if (null === $prefix || str_starts_with($path, '/')) {
								$routeCollector->addRoute($httpMethod->value, $path, [
									$this->container->get($class),
									$reflectionClassMethod->getName(),
								]);
								continue;
							}

							$routeCollector->addRoute($httpMethod->value, $prefix . '/' . $path, [
								$this->container->get($class),
								$reflectionClassMethod->getName(),
							]);
						}
					}
				}
			}
		}

		return $routeCollector;
	}
}