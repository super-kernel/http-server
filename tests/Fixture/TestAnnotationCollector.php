<?php

declare(strict_types=1);

namespace SuperKernel\HttpServer\Tests\Fixture;

use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Contract\AnnotationCollectorInterface;

use function is_a;

final readonly class TestAnnotationCollector implements AnnotationCollectorInterface
{
    /**
     * @param array<class-string, array<class-string, non-empty-list<AttributeMetadata<object>>>> $classesByAttribute
     * @param array<class-string, list<AttributeMetadata<object>>>                                $classAttributes
     * @param array<string, list<AttributeMetadata<object>>>                                      $methodAttributes
     */
    public function __construct(
        private array $classesByAttribute = [],
        private array $classAttributes = [],
        private array $methodAttributes = [],
    )
    {
    }

    public function getPackages(): array
    {
        return [];
    }

    public function getPackage(string $packageName): ?PackageAnnotations
    {
        return null;
    }

    public function getClassesWithAttribute(string $attribute): array
    {
        return $this->classesByAttribute[$attribute] ?? [];
    }

    public function getClassesWithAttributeAssignableTo(string $attribute): array
    {
        $classes = [];

        foreach ($this->classesByAttribute as $declaration => $annotatedClasses) {
            if (!is_a($declaration, $attribute, true)) {
                continue;
            }

            foreach ($annotatedClasses as $class => $metadata) {
                $classes[$class] = [
                    ...($classes[$class] ?? []),
                    ...$metadata,
                ];
            }
        }

        return $classes;
    }

    public function getClassAttributes(string $class): array
    {
        return $this->classAttributes[$class] ?? [];
    }

    public function getMethodAttributes(string $class, string $method): array
    {
        return $this->methodAttributes[$class . '::' . $method] ?? [];
    }

    public function getMethodsWithAttribute(string $attribute): array
    {
        return [];
    }

    public function getMethodsWithAttributeAssignableTo(string $attribute): array
    {
        return [];
    }

    public function getPropertyAttributes(string $class, string $property): array
    {
        return [];
    }
}
