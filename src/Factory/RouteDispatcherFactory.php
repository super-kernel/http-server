<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Factory;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionMethod;
use RuntimeException;
use SuperKernel\Di\Attribute\Factory;
use SuperKernel\Di\Contract\AttributeCollectorInterface;
use SuperKernel\Di\Contract\ReflectionCollectorInterface;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\Middlewares;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Router\MiddlewareCollector;
use SuperKernel\HttpServer\Router\RouteData;
use SuperKernel\HttpServer\Router\RouteDispatcher;
use function ltrim;
use function str_starts_with;
use function strtoupper;

#[Factory]
final class RouteDispatcherFactory
{
	/**
	 * @var array<string, RouteCollector> $containers
	 */
	private array $containers = [];

	private ?ReflectionCollectorInterface $reflectionCollector = null {
		get => $this->reflectionCollector ??= $this->container->get(ReflectionCollectorInterface::class);
	}

	private ?MiddlewareCollector $middlewareCollector = null {
		get => $this->middlewareCollector ??= $this->container->get(MiddlewareCollector::class);
	}

	public function __construct(private readonly ContainerInterface $container)
	{
	}

	public function getDispatcher(string $serverName): Dispatcher
	{
		$routeCollector = $this->containers[$serverName] ?? new RouteCollector(new Std, new GroupCountBased);

		return new RouteDispatcher($routeCollector->getData(), $serverName);
	}

	/**
	 * @param ReflectionCollectorInterface $reflectionCollector
	 * @param AttributeCollectorInterface  $attributeCollector
	 * @param MiddlewareCollector          $middlewareCollector
	 *
	 * @return $this
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(
		ReflectionCollectorInterface $reflectionCollector,
		AttributeCollectorInterface  $attributeCollector,
		MiddlewareCollector          $middlewareCollector,
	): RouteDispatcherFactory
	{
		foreach ($attributeCollector->getAttributes(Controller::class) as $attribute) {
			/* @var Controller $controller */
			$controller      = $attribute->attribute;
			$reflectionClass = $reflectionCollector->reflectClass($attribute->class);

			$controllerMiddlewares = $this->getMiddlewares($reflectionClass->getAttributes(Middlewares::class));

			foreach ($this->reflectionCollector->reflectClass($attribute->class)->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectMethod) {

				$actionMiddlewares = $this->getMiddlewares($reflectMethod->getAttributes(Middlewares::class));

				$routeData = new RouteData(
					controller : $attribute->class,
					action     : $reflectMethod->getName(),
					middlewares: $controllerMiddlewares + $actionMiddlewares,
				);

				$this->collectRoute($controller, $routeData, $reflectMethod);
			}
		}

		return $this;
	}

	private function collectRoute(
		Controller       $controller,
		RouteData        $routeData,
		ReflectionMethod $reflectionMethod,
	): void
	{
		$serverName = $controller->server;

		if (!isset($this->containers[$serverName])) {
			$this->containers[$serverName] = new RouteCollector(new Std, new GroupCountBased);
		}

		foreach ($reflectionMethod->getAttributes(RequestMapping::class) as $reflectionAttribute) {
			/* @var RequestMapping $requestMapping */
			$requestMapping = $reflectionAttribute->newInstance();

			$path = str_starts_with($requestMapping->path, '/')
				? $requestMapping->path
				: ltrim($controller->prefix) . '/' . $requestMapping->path;

			$methods = str_contains($requestMapping->methods, ',')
				? explode(',', $requestMapping->methods)
				: [$requestMapping->methods];

			foreach ($methods as $method) {
				$httpMethod = strtoupper($method);

				$this->containers[$serverName]->addRoute($httpMethod, $path, $routeData);
			}
		}
	}

	/**
	 * @param array $reflectionAttributes
	 *
	 * @return array
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	private function getMiddlewares(array $reflectionAttributes): array
	{
		$middlewares = [];

		foreach ($reflectionAttributes as $reflectionAttribute) {
			/* @var Middlewares $attributeInstance */
			$attributeInstance = $reflectionAttribute->newInstance();

			foreach ($attributeInstance->middlewares as $middleware => $priority) {
				if (!is_subclass_of($middleware, MiddlewareInterface::class)) {
					throw new RuntimeException(
						sprintf('%s must implement %s', $middleware, MiddlewareInterface::class));
				}

				$middlewares[] = [
					$this->container->get($middleware),
					$priority,
				];
			}
		}

		return $middlewares;
	}
}