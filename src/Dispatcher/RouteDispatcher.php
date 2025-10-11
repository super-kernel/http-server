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
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use SuperKernel\Attribute\Contract;
use SuperKernel\Attribute\Factory;
use SuperKernel\Contract\ReflectionManagerInterface;
use SuperKernel\HttpServer\Attribute\HttpController;
use SuperKernel\HttpServer\Attribute\RequestMapping;
use SuperKernel\HttpServer\Enumeration\Method;
use Swoole\Http\Request;
use Swoole\Http\Response;

#[
	Contract(Dispatcher::class),
	Factory,
]
final class RouteDispatcher
{
	private Dispatcher\GroupCountBased $dispatcher;

	public function dispatch(Request $request, Response $response): void
	{
		$httpMethod = $request->getMethod();
		$uri        = $request->server['request_uri'];
		$routeInfo  = $this->dispatcher->dispatch($httpMethod, $uri);

		switch ($routeInfo[0]) {
			case Dispatcher::NOT_FOUND:
				$response->end('404 Not Found.');
				break;
			case Dispatcher::METHOD_NOT_ALLOWED:
				$response->end('405 Method Not Allowed.');
				break;
			case Dispatcher::FOUND:
				/**
				 * @var ResponseInterface $responseBody
				 */
				$responseBody = call_user_func($routeInfo[1]);
				$response->end($responseBody->getBody()->getContents());
		}
	}

	/**
	 * @param ContainerInterface $container
	 *
	 * @return RouteDispatcher
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 */
	public function __invoke(ContainerInterface $container): RouteDispatcher
	{
		/* @var ReflectionManagerInterface $reflectionManager */
		$reflectionManager = $container->get(ReflectionManagerInterface::class);
		$classes           = $reflectionManager->getAttributes(HttpController::class);
		$routeCollector    = new RouteCollector(new Std(), new GroupCountBased());

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
									$container->get($class),
									$reflectionClassMethod->getName(),
								]);
								continue;
							}

							$routeCollector->addRoute($httpMethod->value, $prefix . '/' . $path, [
								$container->get($class),
								$reflectionClassMethod->getName(),
							]);
						}
					}
				}
			}
		}

		$this->dispatcher = new Dispatcher\GroupCountBased($routeCollector->getData());

		return $this;
	}
}