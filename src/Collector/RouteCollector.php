<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Collector;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteParser\Std;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use SuperKernel\Di\Attribute\Factory;
use SuperKernel\Di\Contract\AttributeCollectorInterface;
use SuperKernel\Di\Contract\ReflectionCollectorInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use function strtoupper;

#[Factory]
final class RouteCollector
{
	/**
	 * @var array<string, \FastRoute\RouteCollector> $containers
	 */
	private array $containers = [];

	public function getRouteCollector(string $serverName): \FastRoute\RouteCollector
	{
		return $this->containers[$serverName] ??= new \FastRoute\RouteCollector(new Std, new GroupCountBased);
	}

	/**
	 * @return $this
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ContainerInterface           $container,
		ReflectionCollectorInterface $reflectionCollector,
		AttributeCollectorInterface  $attributeCollector,
		MiddlewareCollector          $middlewareCollector,
	): RouteCollector
	{
		foreach ($attributeCollector->getAttributes(HttpController::class) as $class => $attributes) {
			/* @var HttpController $attribute */
			foreach ($attributes as $attribute) {
				$serverName = $attribute->server;

				if (!isset($this->containers[$serverName])) {
					$this->containers[$serverName] = new \FastRoute\RouteCollector(new Std, new GroupCountBased);
				}

				foreach ($reflectionCollector->reflectClass($class)->getMethods() as $reflectMethod) {
					$methodName = $reflectMethod->getName();

					foreach ($reflectMethod->getAttributes(RequestMapping::class) as $methodAttribute) {
						/* @var RequestMapping $requestMapping */
						$requestMapping = $methodAttribute->newInstance();

						$path        = $requestMapping->path;
						$httpMethods = str_contains($requestMapping->methods, ',')
							? explode(',', $requestMapping->methods)
							: [$requestMapping->methods];

						$handler = new RouteData(
							controller : $class,
							action     : $methodName,
							object     : $container->get($class),
							middlewares: $middlewareCollector->getScanMiddleware($class, $methodName),
						);

						foreach ($httpMethods as $httpMethod) {
							$httpMethod = strtoupper($httpMethod);

							if (str_starts_with($path, '/')) {
								$this->containers[$serverName]->addRoute($httpMethod, $path, $handler);
							} else {
								$this->containers[$serverName]->addRoute($httpMethod, $attribute->prefix . '/' . $path, $handler);
							}
						}
					}
				}
			}
		}

		return $this;
	}
}