<?php
declare(strict_types=1);

namespace SuperKernel\HttpServer\Provider;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use SuperKernel\Attribute\Autowired;
use SuperKernel\Attribute\Factory;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\HttpServer\Attribute\Controller;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Contract\MiddlewareCollectorInterface;
use SuperKernel\HttpServer\Factory\DispatcherFactory;
use SuperKernel\HttpServer\Router\RouteData;
use function array_merge;

#[
	Provider(DispatcherFactory::class),
	Factory,
]
final readonly class DispatcherFactoryProvider
{
	#[Autowired]
	protected MiddlewareCollectorInterface $middlewareCollector;

	public function __invoke(AnnotationCollectorInterface $annotationCollector): DispatcherFactory
	{
		$containers = [];

		foreach ($annotationCollector->getClassesByAttribute(Controller::class) as $controllerAnnotation) {
			$controllerClass = $controllerAnnotation->getClass();
			/* @var Controller $controller */
			$controller = $controllerAnnotation->getInstance();

			$serverName = $controllerAnnotation->getInstance()->server;

			if (!isset($containers[$serverName])) {
				$containers[$serverName] = new RouteCollector(new Std(), new GroupCountBased());
			}

			foreach ($annotationCollector->getMethodsByAttribute(RequestMapping::class) as $requestMappingAnnotation) {
				/* @var RequestMapping $requestMapping */
				$requestMapping = $requestMappingAnnotation->getInstance();

				try {
					$action = $requestMappingAnnotation->getMethod();
				}
				catch (\Throwable $throwable) {
					var_dump($throwable->getMessage());
				}


				$routeData = new RouteData(
					controller : $controllerClass,
					action     : $action,
					middlewares: $this->getMiddlewares($serverName, $controllerClass, $action),
				);

				$path = str_starts_with($requestMapping->path, '/')
					? $requestMapping->path
					: ltrim($controller->prefix) . '/' . $requestMapping->path;

				$methods = str_contains($requestMapping->methods, ',')
					? explode(',', $requestMapping->methods)
					: [$requestMapping->methods];

				foreach ($methods as $method) {
					$httpMethod = strtoupper($method);

					$containers[$serverName]->addRoute($httpMethod, $path, $routeData);
				}
			}
		}

		return new DispatcherFactory($containers);
	}

	private function getMiddlewares(string $serverName, string $class, string $method): array
	{
		$serverMiddlewares = $this->middlewareCollector->getServerMiddlewares($serverName);
		$controllerMiddlewares = $this->middlewareCollector->getControllerMiddlewares($class);
		$actionMiddlewares = $this->middlewareCollector->getActionMiddlewares($class, $method);

		return array_merge($serverMiddlewares, $controllerMiddlewares, $actionMiddlewares);
	}
}