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
            $value = $this->find($classes, $exportOwner, $attribute, lastWins: true);
            if ($value !== null) {
                return $value;
            }
        }
        $value = $this->find($classes, $owner, $attribute, lastWins: true);
        if ($value !== null) {
            return $value;
        }
        if ($element->parent() instanceof StructureElement && $this->standard->inheritable($owner, $attribute) && !($owner->value === 'Layout' && $attribute->value === 'LineHeight' && $this->blockLevel($element))) {
            return $this->resolve($element->parent(), $owner, $attribute, $exportOwner, $contextDefault);
        }
        if ($owner->value === 'Layout' && $attribute->value === 'Placement' && $this->standard->semantics === StructureAttributeSemantics::Pdf20) {
            return $contextDefault ?? $this->placementDefault($element);
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
        $placement = $this->find($own, new NameType('Layout'), new NameType('Placement'));
        if ($placement === null) {
            $classes = [];
            foreach ($element->classes === null ? [] : $element->classes->associations as $association) {
                array_push($classes, ...$this->classMap->attributeClass($association->name)->attributes);
            }
            $placement = $this->find($classes, new NameType('Layout'), new NameType('Placement'), lastWins: true);
        }
        if ($placement === null && $this->standard->semantics === StructureAttributeSemantics::Pdf20) {
            $placement = $this->placementDefault($element);
        }
        return $placement instanceof NameType && $placement->value !== 'Inline';
    }

    /** EC3 #188, physical p.791: known standard contexts only; ambiguous layout needs caller context. */
    private function placementDefault(StructureElement $element): ?NameType
    {
        $role = $this->roles->resolve($element->type);
        $blocks = [StandardStructureType::P, StandardStructureType::H, StandardStructureType::H1, StandardStructureType::H2, StandardStructureType::H3, StandardStructureType::H4, StandardStructureType::H5, StandardStructureType::H6, StandardStructureType::L, StandardStructureType::Table];
        $inline = [StandardStructureType::Span, StandardStructureType::Quote, StandardStructureType::Note, StandardStructureType::Reference, StandardStructureType::BibEntry, StandardStructureType::Code, StandardStructureType::Link, StandardStructureType::Annot, StandardStructureType::Ruby, StandardStructureType::RB, StandardStructureType::RT, StandardStructureType::RP, StandardStructureType::Warichu, StandardStructureType::WT, StandardStructureType::WP];
        if (in_array($role, $blocks, true)) {
            return new NameType('Block');
        }
        if (in_array($role, $inline, true)) {
            return new NameType('Inline');
        }
        $parent = $element->parent();
        if (in_array($role, [StandardStructureType::Figure, StandardStructureType::Formula, StandardStructureType::Form], true) && $parent instanceof StructureElement) {
            $parentRole = $this->roles->resolve($parent->type);
            if ($parentRole?->grouping()) {
                return new NameType('Block');
            }
            if (in_array($parentRole, [...$blocks, ...$inline], true) && !in_array($parentRole, [StandardStructureType::L, StandardStructureType::Table], true)) {
                return new NameType('Inline');
            }
        }
        return null;
    }

    /**
     * EC3 #289, physical page 753: only C uses later-in-array precedence.
     * @param list<StructureAttributeObject> $objects
     */
    private function find(array $objects, NameType $owner, NameType $attribute, bool $lastWins = false): ?BasicType
    {
        $found = null;
        foreach ($objects as $object) {
            if ($object->owner->value !== $owner->value) {
                continue;
            }
            $value = $object->dictionary()->get($attribute);
            if ($value !== null) {
                if (!$lastWins && $found !== null && $found !== $value) {
                    throw new \LogicException('Multiple attribute objects provide conflicting values for /' . $owner->value . '/' . $attribute->value . '.');
                }
                $found = $value;
            }
        }
        return $found;
    }
}
