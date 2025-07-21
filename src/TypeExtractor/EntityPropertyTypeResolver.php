<?php
namespace Azura\Normalizer\TypeExtractor;

use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeContext\TypeContext;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;

/**
 * Resolves type for a given PropertyType reflection.
 *
 * TODO: This class is only overwritten to use getSettableType() instead of getType().
 */
final readonly class EntityPropertyTypeResolver implements TypeResolverInterface
{
    public function __construct(
        private EntityTypeResolver $reflectionTypeResolver,
        private TypeContextFactory $typeContextFactory,
    ) {
    }

    public function resolve(mixed $subject, ?TypeContext $typeContext = null): Type
    {
        if (!$subject instanceof \ReflectionProperty) {
            throw new UnsupportedException(\sprintf('Expected subject to be a "ReflectionProperty", "%s" given.', get_debug_type($subject)), $subject);
        }

        $typeContext ??= $this->typeContextFactory->createFromReflection($subject);

        try {
            return $this->reflectionTypeResolver->resolve($subject->getSettableType(), $typeContext);
        } catch (UnsupportedException $e) {
            $path = \sprintf('%s::$%s', $subject->getDeclaringClass()->getName(), $subject->getName());

            throw new UnsupportedException(\sprintf('Cannot resolve type for "%s".', $path), $subject, previous: $e);
        }
    }
}
