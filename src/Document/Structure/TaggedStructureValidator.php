<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Standard-role and attribute relations; layout, word breaks and intended reading order remain explicit. */
final readonly class TaggedStructureValidator
{
    public function __construct(private RoleMap $roles, private AttributeResolver $attributes)
    {
    }

    public function validate(StructureTreeIndex $index): void
    {
        $identifiers = [];
        foreach ($index->elements as $element) {
            if ($element->identifier !== null) {
                $identifiers[$element->identifier->value] = $element;
            }
        }
        foreach ($index->elements as $element) {
            $role = $this->roles->resolve($element->type);
            if ($role === null) {
                throw new \InvalidArgumentException('Every Tagged PDF structure type must resolve to a standard role.');
            }
            if (in_array($role, [StandardStructureType::Link, StandardStructureType::Annot], true)) {
                $parent = $element->parent();
                while ($parent instanceof StructureElement) {
                    if ($this->roles->resolve($parent->type) === $role) {
                        throw new \InvalidArgumentException('Link or Annot structure elements cannot nest inside the same role (PDF pages 597/599).');
                    }
                    $parent = $parent->parent();
                }
            }
            if ($role->grouping()) {
                foreach ($element->kids() as $child) {
                    if (!$child instanceof StructureElement) {
                        throw new \InvalidArgumentException('Grouping elements contain other structure elements, not content items (14.8.4.2).');
                    }
                }
            }
            $this->validateTableAttributes($element, $role, $identifiers);
            foreach (['ColumnCount', 'ColumnGap', 'ColumnWidths'] as $key) {
                if ($this->attributes->hasExplicit($element, new NameType('Layout'), new NameType($key)) && !in_array($role, [StandardStructureType::Art, StandardStructureType::Sect, StandardStructureType::Div], true)) {
                    throw new \InvalidArgumentException('Column attributes apply to Art, Sect and Div grouping elements.');
                }
            }
            if ($this->attributes->hasExplicit($element, new NameType('List'), new NameType('ListNumbering')) && $role !== StandardStructureType::L) {
                throw new \InvalidArgumentException('An explicit ListNumbering attribute belongs to an L element.');
            }
            if (in_array($role, [StandardStructureType::Table, StandardStructureType::Figure], true) && count($this->contentPages($element)) === 1 && $this->attributes->resolve($element, new NameType('Layout'), new NameType('BBox')) === null) {
                throw new \InvalidArgumentException('A figure or table entirely on one page requires a Layout BBox attribute (table 344).');
            }
            if (in_array($role, [StandardStructureType::Figure, StandardStructureType::Formula, StandardStructureType::Form], true)) {
                if (in_array($role, [StandardStructureType::Figure, StandardStructureType::Formula], true) && $element->get('Alt') === null && $element->get('ActualText') === null) {
                    throw new \InvalidArgumentException('Tagged PDF figures and formulas require alternate description or replacement text (PDF page 601).');
                }
                $placement = $this->attributes->resolve($element, new NameType('Layout'), new NameType('Placement'));
                $dimension = $placement instanceof NameType ? match ($placement->value) {
                    'Inline' => 'Width',
                    'Block' => 'Height',
                    default => null,
                } : null;
                if ($dimension !== null && !$this->attributes->resolve($element, new NameType('Layout'), new NameType($dimension)) instanceof NumericType) {
                    throw new \InvalidArgumentException('An illustration requires an explicit numeric ' . $dimension . ' for its Placement (PDF page 615).');
                }
            }
            if ($role === StandardStructureType::Ruby) {
                $children = array_map(fn (StructureElement|PageMarkedContent|MarkedContentReference|ObjectReference $kid): ?StandardStructureType => $kid instanceof StructureElement ? $this->roles->resolve($kid->type) : null, $element->kids());
                if ($children !== [StandardStructureType::RB, StandardStructureType::RT] && $children !== [StandardStructureType::RB, StandardStructureType::RP, StandardStructureType::RT, StandardStructureType::RP]) {
                    throw new \InvalidArgumentException('A Ruby assembly requires RB,RT or RB,RP,RT,RP (table 339).');
                }
            }
            if ($role === StandardStructureType::Form && $this->attributes->resolve($element, new NameType('PrintField'), new NameType('Role')) === null) {
                $kids = $element->kids();
                if (count($kids) !== 1 || !$kids[0] instanceof ObjectReference) {
                    throw new \InvalidArgumentException('An interactive Form structure element requires exactly one widget object reference (table 340).');
                }
                $object = $kids[0]->object;
                $dictionary = $object instanceof StreamObject ? $object->dictionary() : $object;
                $subtype = $dictionary->get('Subtype');
                if (!$subtype instanceof NameType || $subtype->value !== 'Widget') {
                    throw new \InvalidArgumentException('An interactive Form structure element must reference a Widget annotation.');
                }
            }
        }
    }

    /**
     * Returns the complete transitive header association without repeating cyclic associations.
     * @return list<StructureElement>
     */
    public function headers(StructureElement $cell, StructureTreeIndex $index): array
    {
        $byId = [];
        foreach ($index->elements as $element) {
            if ($element->identifier !== null) {
                $byId[$element->identifier->value] = $element;
            }
        }
        $result = [];
        $seen = [];
        $queue = [$cell];
        while ($queue !== []) {
            $element = array_pop($queue);
            $headers = $this->attributes->resolve($element, new NameType('Table'), new NameType('Headers'));
            if (!$headers instanceof ArrayType) {
                continue;
            }
            foreach ($headers->items as $id) {
                if (!$id instanceof StringType || !isset($byId[$id->value]) || $this->roles->resolve($byId[$id->value]->type) !== StandardStructureType::TH) {
                    throw new \InvalidArgumentException('Each Headers identifier must resolve through IDTree to a TH element.');
                }
                if (!isset($seen[$id->value])) {
                    $seen[$id->value] = true;
                    $result[] = $byId[$id->value];
                    $queue[] = $byId[$id->value];
                }
            }
        }
        return $result;
    }

    /** @param array<string, StructureElement> $identifiers */
    private function validateTableAttributes(StructureElement $element, StandardStructureType $role, array $identifiers): void
    {
        foreach (['RowSpan', 'ColSpan'] as $key) {
            $value = $this->attributes->resolve($element, new NameType('Table'), new NameType($key));
            if ($value instanceof IntegerType && $value->integer() < 1) {
                throw new \InvalidArgumentException('A table-cell span must be positive.');
            }
            // Defaults do not cause an entry to exist on non-cell elements.
            if (!in_array($role, [StandardStructureType::TH, StandardStructureType::TD], true) && $this->attributes->hasExplicit($element, new NameType('Table'), new NameType($key))) {
                throw new \InvalidArgumentException('Table spans apply only to TH and TD elements.');
            }
        }
        $scope = $this->attributes->resolve($element, new NameType('Table'), new NameType('Scope'));
        if ($scope !== null && ($role !== StandardStructureType::TH || !$scope instanceof NameType || !in_array($scope->value, ['Row', 'Column', 'Both'], true))) {
            throw new \InvalidArgumentException('Scope applies only to TH and must be Row, Column or Both.');
        }
        $headers = $this->attributes->resolve($element, new NameType('Table'), new NameType('Headers'));
        if ($headers !== null) {
            if (!in_array($role, [StandardStructureType::TH, StandardStructureType::TD], true) || !$headers instanceof ArrayType) {
                throw new \InvalidArgumentException('Headers applies only to table header or data cells.');
            }
            foreach ($headers->items as $id) {
                if (!$id instanceof StringType || !isset($identifiers[$id->value]) || $this->roles->resolve($identifiers[$id->value]->type) !== StandardStructureType::TH) {
                    throw new \InvalidArgumentException('Each Headers byte string must name an existing TH element.');
                }
            }
        }
        if ($this->attributes->resolve($element, new NameType('Table'), new NameType('Summary')) !== null && $role !== StandardStructureType::Table) {
            throw new \InvalidArgumentException('A table Summary applies only to Table elements.');
        }
    }

    /** @return array<int, true> */
    private function contentPages(StructureElement $element): array
    {
        $pages = [];
        $queue = [$element];
        while ($queue !== []) {
            $node = array_pop($queue);
            foreach ($node->kids() as $child) {
                if ($child instanceof StructureElement) {
                    $queue[] = $child;
                } else {
                    $page = $child instanceof ObjectReference ? $child->page : $child->container->page;
                    $pages[spl_object_id($page)] = true;
                }
            }
        }
        return $pages;
    }
}
