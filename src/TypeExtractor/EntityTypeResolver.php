<?php

namespace Azura\Normalizer\TypeExtractor;

use Symfony\Component\TypeInfo\Exception\InvalidArgumentException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeContext\TypeContext;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;

/**
 * Resolves type for a given type reflection.
 *
 * TODO: This class is only overwritten to temporarily resolve a PHP 8.4 issue.
 */
final class EntityTypeResolver implements TypeResolverInterface
{
    public function resolve(mixed $subject, ?TypeContext $typeContext = null): Type
    {
        if ($subject instanceof \ReflectionUnionType) {
            return Type::union(...array_map(fn (mixed $t): Type => $this->resolve($t, $typeContext), $subject->getTypes()));
        }

        if ($subject instanceof \ReflectionIntersectionType) {
            return Type::intersection(...array_map(fn (mixed $t): Type => $this->resolve($t, $typeContext), $subject->getTypes()));
        }

        if (!$subject instanceof \ReflectionNamedType) {
            throw new UnsupportedException(\sprintf('Expected subject to be a "ReflectionNamedType", a "ReflectionUnionType" or a "ReflectionIntersectionType", "%s" given.', get_debug_type($subject)), $subject);
        }

        $identifier = $subject->getName();
        $nullable = $subject->allowsNull();

        if (TypeIdentifier::ARRAY->value === $identifier) {
            $type = Type::array();

            return $nullable ? Type::nullable($type) : $type;
        }

        if (TypeIdentifier::ITERABLE->value === $identifier) {
            $type = Type::iterable();

            return $nullable ? Type::nullable($type) : $type;
        }

        if (TypeIdentifier::NULL->value === $identifier || TypeIdentifier::MIXED->value === $identifier) {
            return Type::builtin($identifier);
        }

        if ($subject->isBuiltin()) {
            if (str_contains($identifier, '?')) {
                $identifier = str_replace('?', '', $identifier);
                $nullable = true;
            }

            $type = Type::builtin(TypeIdentifier::from($identifier));

            return $nullable ? Type::nullable($type) : $type;
        }

        if (\in_array(strtolower($identifier), ['self', 'static', 'parent'], true) && !$typeContext) {
            throw new InvalidArgumentException(\sprintf('A "%s" must be provided to resolve "%s".', TypeContext::class, strtolower($identifier)));
        }

        /** @var class-string $className */
        $className = match (true) {
            'self' === strtolower($identifier) => $typeContext->getDeclaringClass(),
            'static' === strtolower($identifier) => $typeContext->getCalledClass(),
            'parent' === strtolower($identifier) => $typeContext->getParentClass(),
            default => $identifier,
        };

        if (is_subclass_of($className, \UnitEnum::class)) {
            $type = Type::enum($className);
        } else {
            $type = Type::object($className);
        }

        return $nullable ? Type::nullable($type) : $type;
    }
}
