<?php

namespace Azura\Normalizer;

use ArrayObject;
use Azura\Normalizer\Attributes\DeepNormalize;
use Azura\Normalizer\Exception\NoGetterAvailableException;
use Azura\Normalizer\TypeExtractor\EntityTypeExtractor;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Proxy\DefaultProxyClassNameResolver;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\Serializer\Mapping\AttributeMetadataInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

final class DoctrineEntityNormalizer extends AbstractObjectNormalizer
{
    public const CLASS_METADATA = 'class_metadata';
    public const ASSOCIATION_MAPPINGS = 'association_mappings';

    public const NORMALIZE_TO_IDENTIFIERS = 'form_mode';

    private EntityTypeExtractor $typeExtractor;

    public function __construct(
        private readonly EntityManagerInterface $em,
        ?ClassMetadataFactoryInterface $classMetadataFactory = null,
        array $defaultContext = []
    ) {
        $defaultContext[AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES] = true;

        $this->typeExtractor = new EntityTypeExtractor();

        parent::__construct(
            classMetadataFactory: $classMetadataFactory,
            propertyTypeExtractor: $this->typeExtractor,
            defaultContext: $defaultContext
        );
    }

    /**
     * Replicates the "toArray" functionality previously present in Doctrine 1.
     *
     * @return array|string|int|float|bool|ArrayObject<int, mixed>|null
     */
    public function normalize(
        mixed $object,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|ArrayObject|null {
        if (!is_object($object)) {
            throw new InvalidArgumentException('Cannot normalize non-object.');
        }

        $context = $this->addDoctrineContext($object::class, $context);

        return parent::normalize($object, $format, $context);
    }

    /**
     * Replicates the "fromArray" functionality previously present in Doctrine 1.
     *
     * @template T as object
     * @param mixed $data
     * @param class-string<T> $type
     * @param string|null $format
     * @param array $context
     * @return T
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): object
    {
        $context = $this->addDoctrineContext($type, $context);

        return parent::denormalize($data, $type, $format, $context);
    }

    /**
     * @param class-string<object> $className
     * @param array $context
     * @return array
     */
    private function addDoctrineContext(
        string $className,
        array $context
    ): array {
        $context[self::CLASS_METADATA] =  $this->em->getClassMetadata($className);
        $context[self::ASSOCIATION_MAPPINGS] = [];

        if ($context[self::CLASS_METADATA]->associationMappings) {
            foreach ($context[self::CLASS_METADATA]->associationMappings as $mappingName => $mappingInfo) {
                $entity = $mappingInfo['targetEntity'];

                if (isset($mappingInfo['joinTable'])) {
                    $context[self::ASSOCIATION_MAPPINGS][$mappingInfo['fieldName']] = [
                        'type' => 'many',
                        'entity' => $entity,
                        'is_owning_side' => ($mappingInfo['isOwningSide'] == 1),
                    ];
                } elseif (isset($mappingInfo['joinColumns'])) {
                    foreach ($mappingInfo['joinColumns'] as $col) {
                        $colName = $col['name'];
                        $colName = $context[self::CLASS_METADATA]->fieldNames[$colName] ?? $colName;

                        $context[self::ASSOCIATION_MAPPINGS][$mappingName] = [
                            'name' => $colName,
                            'type' => 'one',
                            'entity' => $entity,
                        ];
                    }
                }
            }
        }

        return $context;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->isEntity($data);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $this->isEntity($type);
    }

    /**
     * @param object|class-string<object> $classOrObject
     * @param array $context
     * @param bool $attributesAsString
     * @return string[]|AttributeMetadataInterface[]|bool
     */
    protected function getAllowedAttributes(
        $classOrObject,
        array $context,
        bool $attributesAsString = false
    ): array|bool {
        $groups = $this->getGroups($context);
        if (empty($groups)) {
            return false;
        }

        return parent::getAllowedAttributes($classOrObject, $context, $attributesAsString);
    }

    protected function extractAttributes(object $object, ?string $format = null, array $context = []): array
    {
        $rawProps = new ReflectionClass($object)->getProperties(
            ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED
        );

        $props = [];
        foreach ($rawProps as $rawProp) {
            $props[] = $rawProp->getName();
        }

        return array_filter(
            $props,
            fn($attribute) => $this->isAllowedAttribute($object, $attribute, $format, $context)
        );
    }

    /**
     * @param object|class-string<object> $classOrObject
     * @param string $attribute
     * @param string|null $format
     * @param array $context
     * @return bool
     * @throws ReflectionException
     */
    protected function isAllowedAttribute(
        object|string $classOrObject,
        string $attribute,
        ?string $format = null,
        array $context = []
    ): bool {
        if (!parent::isAllowedAttribute($classOrObject, $attribute, $format, $context)) {
            return false;
        }

        $class = \is_object($classOrObject) ? $classOrObject::class : $classOrObject;

        if (isset($context[self::CLASS_METADATA]->associationMappings[$attribute])) {
            if (!$this->supportsDeepNormalization($class, $attribute)) {
                return false;
            }
        }

        return $this->hasGetter($class, $attribute);
    }

    /**
     * @param class-string $className
     * @param string $attribute
     * @return bool Whether a getter exists that can return for this property.
     */
    private function hasGetter(string $className, string $attribute): bool
    {
        if (null !== $this->typeExtractor->getAccessorMethod($className, $attribute)) {
            return true;
        }

        try {
            $reflProp = new ReflectionProperty($className, $attribute);
            return $reflProp->isPublic();
        } catch (ReflectionException) {}

        return false;
    }

    protected function getAttributeValue(
        object $object,
        string $attribute,
        ?string $format = null,
        array $context = []
    ): mixed {
        $formMode = $context[self::NORMALIZE_TO_IDENTIFIERS] ?? false;

        if (isset($context[self::CLASS_METADATA]->associationMappings[$attribute])) {
            if (!$this->supportsDeepNormalization($object::class, $attribute)) {
                throw new NoGetterAvailableException(
                    sprintf(
                        'Deep normalization disabled for property %s.',
                        $attribute
                    )
                );
            }
        }

        $value = $this->getProperty($object, $attribute);

        // Special handling for Doctrine "many-to-x" relationships (Collections)
        if ($value instanceof Collection) {
            if ($formMode) {
                $value = array_filter(array_map(
                    function(object $valObj) {
                        $idField = $this->em->getClassMetadata($valObj::class)->identifier;
                        return $idField && count($idField) === 1
                            ? $this->getProperty($valObj, $idField[0])
                            : null;
                    },
                    $value->getValues(),
                ));
            } else {
                $value = $value->getValues();
            }
        }

        return $value;
    }

    /**
     * @param class-string $className
     * @param string $attribute
     * @return bool
     */
    private function supportsDeepNormalization(string $className, string $attribute): bool
    {
        try {
            $reflProp = new ReflectionProperty($className, $attribute);
            $deepNormalizeAttrs = $reflProp->getAttributes(DeepNormalize::class);

            if (empty($deepNormalizeAttrs)) {
                return false;
            }

            /** @var DeepNormalize $deepNormalize */
            $deepNormalize = current($deepNormalizeAttrs)->newInstance();
            return $deepNormalize->getDeepNormalize();
        } catch (\ReflectionException) {
            return false;
        }
    }

    private function getProperty(object $entity, string $key): mixed
    {
        if (null !== $accessor = $this->typeExtractor->getAccessorMethod($entity::class, $key)) {
            [$method, $prefix] = $accessor;
            return $method->invoke($entity);
        }

        try {
            $reflProp = new ReflectionProperty($entity::class, $key);
            if ($reflProp->isPublic()) {
                return $reflProp->getValue($entity);
            }
        } catch (ReflectionException) {}

        throw new NoGetterAvailableException(sprintf('No getter is available for property %s.', $key));
    }

    protected function setAttributeValue(
        object $object,
        string $attribute,
        mixed $value,
        ?string $format = null,
        array $context = []
    ): void {
        // Special handling for Doctrine entity relationship fields.
        if (isset($context[self::ASSOCIATION_MAPPINGS][$attribute])) {
            $mapping = $context[self::ASSOCIATION_MAPPINGS][$attribute];

            if ('one' === $mapping['type']) {
                // Allow passing either a related object or simply its ID to a "one-to-x" relationship.

                /** @var class-string $entity */
                $entity = $mapping['entity'];

                if (empty($value)) {
                    $this->setProperty($object, $attribute, null);
                } else if ($value instanceof $entity) {
                    $this->setProperty($object, $attribute, $value);
                } else if (($fieldItem = $this->em->find($entity, $value)) instanceof $entity) {
                    $this->setProperty($object, $attribute, $fieldItem);
                }
            } elseif ($mapping['is_owning_side']) {
                // Convert an array of entities or identifiers to a Doctrine collection for "many-to-x" relationships.

                $collection = $this->getProperty($object, $attribute);

                if ($collection instanceof Collection) {
                    $collection->clear();

                    if ($value) {
                        foreach ((array)$value as $fieldId) {
                            /** @var class-string $entity */
                            $entity = $mapping['entity'];

                            $fieldItem = $this->em->find($entity, $fieldId);
                            if ($fieldItem instanceof $entity) {
                                $collection->add($fieldItem);
                            }
                        }
                    }
                }
            }
        } else {
            $this->setProperty($object, $attribute, $value);
        }
    }

    private function setProperty(
        object $entity,
        string $key,
        mixed $value
    ): void {
        // Prefer setter if it exists.
        if (null !== $mutator = $this->typeExtractor->getMutatorMethod($entity::class, $key)) {
            [$method, $prefix] = $mutator;
            $method->invoke($entity, $value);
            return;
        }

        // Try directly setting on the property.
        try {
            $reflProp = new ReflectionProperty($entity::class, $key);
            if ($reflProp->isPublic() && !$reflProp->isProtectedSet() && !$reflProp->isPrivateSet()) {
                $reflProp->setValue($entity, $value);
                return;
            }
        } catch (ReflectionException) {}
    }

    private function isEntity(mixed $class): bool
    {
        if (is_object($class)) {
            $class = DefaultProxyClassNameResolver::getClass($class);
        }

        if (!is_string($class) || !class_exists($class)) {
            return false;
        }

        return !$this->em->getMetadataFactory()->isTransient($class);
    }

    public function getSupportedTypes(?string $format): array
    {
        return ['object' => true];
    }
}
