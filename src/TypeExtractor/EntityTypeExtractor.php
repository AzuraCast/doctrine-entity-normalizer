<?php

namespace Azura\Normalizer\TypeExtractor;

use Azura\Normalizer\DoctrineEntityNormalizer;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\ReflectionParameterTypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\ReflectionReturnTypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\ReflectionTypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;

final class EntityTypeExtractor implements PropertyTypeExtractorInterface
{
    private TypeResolverInterface $typeResolver;

    private readonly Inflector $inflector;

    private const MAP_TYPES = [
        'integer' => TypeIdentifier::INT->value,
        'boolean' => TypeIdentifier::BOOL->value,
        'double' => TypeIdentifier::FLOAT->value,
    ];

    public function __construct(
        ?Inflector $inflector = null
    ) {
        $typeContextFactory = new TypeContextFactory();

        $reflectionTypeResolver = new ReflectionTypeResolver();
        $entityTypeResolver = new EntityTypeResolver();

        $this->typeResolver = TypeResolver::create([
            \ReflectionType::class => $entityTypeResolver,
            \ReflectionParameter::class => new ReflectionParameterTypeResolver($reflectionTypeResolver, $typeContextFactory),
            \ReflectionProperty::class => new EntityPropertyTypeResolver($entityTypeResolver, $typeContextFactory),
            \ReflectionFunctionAbstract::class => new ReflectionReturnTypeResolver($reflectionTypeResolver, $typeContextFactory),
        ]);

        $this->inflector = $inflector ?? InflectorFactory::create()->build();
    }

    /**
     * @deprecated since Symfony 7.3, use "getType" instead
     */
    public function getTypes(string $class, string $property, array $context = []): ?array
    {
        return null;
    }

    public function getType(string $class, string $property, array $context = []): ?Type
    {
        // If the element is a Doctrine mapping, let the normalizer handle it.
        if (isset($context[DoctrineEntityNormalizer::ASSOCIATION_MAPPINGS][$property])) {
            return null;
        }

        // Check setters first.
        if (null !== $mutator = $this->getMutatorMethod($class, $property)) {
            [$mutatorReflection, $prefix] = $mutator;
            try {
                return $this->typeResolver->resolve($mutatorReflection->getParameters()[0]);
            } catch (UnsupportedException) {
            }
        }

        // Check getters.
        if (null !== $accessor = $this->getAccessorMethod($class, $property)) {
            [$accessorReflection, $prefix] = $accessor;
            try {
                return $this->typeResolver->resolve($accessorReflection);
            } catch (UnsupportedException) {
            }
        }

        // Check the property itself.
        try {
            /** @var class-string $class */
            $reflectionClass = new \ReflectionClass($class);
            $reflectionProperty = $reflectionClass->getProperty($property);
        } catch (ReflectionException) {
            return null;
        }

        try {
            return $this->typeResolver->resolve($reflectionProperty);
        } catch (UnsupportedException) {
        }

        if (null === $defaultValue = ($reflectionClass->getDefaultProperties()[$property] ?? null)) {
            return null;
        }

        $typeIdentifier = TypeIdentifier::from(self::MAP_TYPES[\gettype($defaultValue)] ?? \gettype($defaultValue));
        $type = 'array' === $typeIdentifier->value ? Type::array() : Type::builtin($typeIdentifier);

        return (null !== $reflectionProperty->getSettableType() && $reflectionProperty->getSettableType()->allowsNull())
            ? Type::nullable($type)
            : $type;
    }

    /**
     * Gets the accessor method.
     *
     * Returns an array with an instance of \ReflectionMethod as the first key
     * and the prefix of the method as the second, or null if not found.
     *
     * @return array{ReflectionMethod, string}|null
     */
    public function getAccessorMethod(string $class, string $property): ?array
    {
        foreach(['get', 'is', ''] as $prefix) {
            try {
                $reflectionMethod = new ReflectionMethod($class, $this->getMethodName($property, $prefix));
                if ($reflectionMethod->isStatic()) {
                    continue;
                }

                if (0 === $reflectionMethod->getNumberOfRequiredParameters()) {
                    return [$reflectionMethod, $prefix];
                }
            } catch (ReflectionException) {
                // Return null if the property doesn't exist
            }
        }

        return null;
    }

    /**
     * Returns an array with an instance of \ReflectionMethod as the first key
     * and the prefix of the method as the second, or null if not found.
     *
     * @return array{ReflectionMethod, string}|null
     */
    public function getMutatorMethod(string $class, string $property): ?array
    {
        foreach(['set'] as $prefix) {
            try {
                $reflectionMethod = new ReflectionMethod($class, $this->getMethodName($property, $prefix));
                if ($reflectionMethod->isStatic()) {
                    continue;
                }

                if ($reflectionMethod->getNumberOfParameters() >= 1) {
                    return [$reflectionMethod, $prefix];
                }
            } catch (ReflectionException) {
                // Return null if the property doesn't exist
            }
        }

        return null;
    }

    /**
     * Converts "getvar_name_blah" to "getVarNameBlah".
     */
    private function getMethodName(string $var, string $prefix = ''): string
    {
        return $this->inflector->camelize(($prefix ? $prefix . '_' : '') . $var);
    }
}
