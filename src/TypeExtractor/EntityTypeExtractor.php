<?php

namespace Azura\Normalizer\TypeExtractor;

use Azura\Normalizer\DoctrineEntityNormalizer;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use ReflectionClass;
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

    private ?array $currentContext = null;

    private array $reflClassLookup = [];

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

    public function setCurrentContext(?array $value = null): void
    {
        $this->currentContext = $value;
    }

    /**
     * @deprecated since Symfony 7.3, use "getType" instead
     */
    public function getTypes(string $class, string $property, array $context = []): ?array
    {
        return null;
    }

    /**
     * @template T as object
     * @param class-string<T> $class
     * @param string $property
     * @param array $context
     * @return Type|null
     */
    public function getType(string $class, string $property, array $context = []): ?Type
    {
        // If the element is a Doctrine mapping, let the normalizer handle it.
        if (isset($this->currentContext[DoctrineEntityNormalizer::ASSOCIATION_MAPPINGS][$property])) {
            return null;
        }

        $reflClass = $this->getReflectionClass($class);

        // Check setters first.
        if (null !== $mutator = $this->getMutatorMethod($reflClass, $property)) {
            [$mutatorReflection, $prefix] = $mutator;
            try {
                return $this->typeResolver->resolve($mutatorReflection->getParameters()[0]);
            } catch (UnsupportedException) {
            }
        }

        // Check getters.
        if (null !== $accessor = $this->getAccessorMethod($reflClass, $property)) {
            [$accessorReflection, $prefix] = $accessor;
            try {
                return $this->typeResolver->resolve($accessorReflection);
            } catch (UnsupportedException) {
            }
        }

        // Check the property itself.
        if (!$reflClass->hasProperty($property)) {
            return null;
        }

        $reflProp = $reflClass->getProperty($property);

        try {
            return $this->typeResolver->resolve($reflProp);
        } catch (UnsupportedException) {
        }

        if (null === $defaultValue = ($reflClass->getDefaultProperties()[$property] ?? null)) {
            return null;
        }

        $typeIdentifier = TypeIdentifier::from(self::MAP_TYPES[\gettype($defaultValue)] ?? \gettype($defaultValue));
        $type = 'array' === $typeIdentifier->value ? Type::array() : Type::builtin($typeIdentifier);

        return (null !== $reflProp->getSettableType() && $reflProp->getSettableType()->allowsNull())
            ? Type::nullable($type)
            : $type;
    }

    /**
     * Gets the accessor method.
     *
     * Returns an array with an instance of \ReflectionMethod as the first key
     * and the prefix of the method as the second, or null if not found.
     *
     * @template T as object
     * @param ReflectionClass<T> $reflClass
     * @param string $property
     * @return array{ReflectionMethod, string}|null
     */
    public function getAccessorMethod(ReflectionClass $reflClass, string $property): ?array
    {
        foreach(['get', 'is', ''] as $prefix) {
            $methodName = $this->getMethodName($property, $prefix);
            if (!$reflClass->hasMethod($methodName)) {
                continue;
            }

            $reflMethod = $reflClass->getMethod($methodName);
            if ($reflMethod->isStatic()) {
                continue;
            }

            if (0 === $reflMethod->getNumberOfParameters()) {
                return [$reflMethod, $prefix];
            }
        }

        return null;
    }

    /**
     * Returns an array with an instance of \ReflectionMethod as the first key
     * and the prefix of the method as the second, or null if not found.
     *
     * @template T as object
     * @param ReflectionClass<T> $reflClass
     * @param string $property
     * @return array{ReflectionMethod, string}|null
     */
    public function getMutatorMethod(ReflectionClass $reflClass, string $property): ?array
    {
        foreach(['set'] as $prefix) {
            $methodName = $this->getMethodName($property, $prefix);
            if (!$reflClass->hasMethod($methodName)) {
                continue;
            }

            $reflMethod = $reflClass->getMethod($methodName);
            if ($reflMethod->isStatic()) {
                continue;
            }

            if ($reflMethod->getNumberOfParameters() >= 1) {
                return [$reflMethod, $prefix];
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

    /**
     * @template T as object
     * @param class-string<T>|T $classOrObject
     * @return ReflectionClass<T>
     */
    public function getReflectionClass(
        string|object $classOrObject
    ): ReflectionClass {
        $class = \is_object($classOrObject) ? $classOrObject::class : $classOrObject;

        if (!isset($this->reflClassLookup[$class])) {
            $this->reflClassLookup[$class] = $reflClass = new ReflectionClass($class);
            return $reflClass;
        }

        return $this->reflClassLookup[$class];
    }
}
