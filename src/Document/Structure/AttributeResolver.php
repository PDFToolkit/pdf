<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\NameType;

/** Implements A/export > A/standard > C > inheritable parent > default (14.8.5.3, page 602). */
final readonly class AttributeResolver
{
    public function __construct(private ClassMap $classMap, private StandardStructureAttributes $standard = new StandardStructureAttributes(), private RoleMap $roles = new RoleMap())
    {
    }

    public function resolve(
        StructureElement $element,
        NameType $owner,
        NameType $attribute,
        ?NameType $exportOwner = null,
        ?BasicType $contextDefault = null,
    ): ?BasicType {
        $own = array_map(static fn (AttributeAssociation $association): StructureAttributeObject => $association->attribute, $element->attributes === null ? [] : $element->attributes->associations);
        if ($exportOwner !== null) {
            $exported = $this->find($own, $exportOwner, $attribute);
            if ($exported !== null) {
                return $exported;
            }
        }
        $value = $this->find($own, $owner, $attribute);
        if ($value !== null) {
            return $value;
        }
        $classes = [];
        foreach ($element->classes === null ? [] : $element->classes->associations as $association) {
            array_push($classes, ...$this->classMap->attributeClass($association->name)->attributes);
        }
        if ($exportOwner !== null) {
            $value = $this->find($classes, $exportOwner, $attribute);
            if ($value !== null) {
                return $value;
            }
        }
        $value = $this->find($classes, $owner, $attribute);
        if ($value !== null) {
            return $value;
        }
        if ($element->parent() instanceof StructureElement && $this->standard->inheritable($owner, $attribute) && !($owner->value === 'Layout' && $attribute->value === 'LineHeight' && $this->blockLevel($element))) {
            return $this->resolve($element->parent(), $owner, $attribute, $exportOwner, $contextDefault);
        }
        return $this->standard->defaultValue($owner, $attribute) ?? $contextDefault;
    }

    /** Explicit A/C presence, excluding inherited values and normative defaults. */
    public function hasExplicit(StructureElement $element, NameType $owner, NameType $attribute): bool
    {
        foreach ($element->attributes === null ? [] : $element->attributes->associations as $association) {
            if ($association->attribute->owner->value === $owner->value && $association->attribute->dictionary()->has($attribute)) {
                return true;
            }
        }
        foreach ($element->classes === null ? [] : $element->classes->associations as $association) {
            foreach ($this->classMap->attributeClass($association->name)->attributes as $object) {
                if ($object->owner->value === $owner->value && $object->dictionary()->has($attribute)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function blockLevel(StructureElement $element): bool
    {
        $role = $this->roles->resolve($element->type);
        if (in_array($role, [StandardStructureType::P, StandardStructureType::H, StandardStructureType::H1, StandardStructureType::H2, StandardStructureType::H3, StandardStructureType::H4, StandardStructureType::H5, StandardStructureType::H6, StandardStructureType::L, StandardStructureType::LI, StandardStructureType::Lbl, StandardStructureType::LBody, StandardStructureType::Table], true)) {
            return true;
        }
        $own = array_map(static fn (AttributeAssociation $association): StructureAttributeObject => $association->attribute, $element->attributes === null ? [] : $element->attributes->associations);
        foreach ($element->classes === null ? [] : $element->classes->associations as $association) {
            array_push($own, ...$this->classMap->attributeClass($association->name)->attributes);
        }
        $placement = $this->find($own, new NameType('Layout'), new NameType('Placement'));
        return $placement instanceof NameType && $placement->value !== 'Inline';
    }

    /** @param list<StructureAttributeObject> $objects */
    private function find(array $objects, NameType $owner, NameType $attribute): ?BasicType
    {
        $found = null;
        foreach ($objects as $object) {
            if ($object->owner->value !== $owner->value) {
                continue;
            }
            $value = $object->dictionary()->get($attribute);
            if ($value !== null) {
                if ($found !== null && $found !== $value) {
                    throw new \LogicException('Multiple attribute objects provide conflicting values for /' . $owner->value . '/' . $attribute->value . '.');
                }
                $found = $value;
            }
        }
        return $found;
    }
}
