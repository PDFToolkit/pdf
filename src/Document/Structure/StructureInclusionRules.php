<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** ISO-TS-32005-2023-sponsored.pdf, clauses 5–7, physical pages 9–46. */
final readonly class StructureInclusionRules
{
    private const array PDF17_ONLY = ['Art', 'BlockQuote', 'TOC', 'TOCI', 'Index', 'Private', 'Quote', 'Note', 'Reference', 'BibEntry', 'Code'];
    private const array TRANSPARENT = ['Part', 'Div', 'NonStruct'];

    public function __construct(public StructureInclusionCorrections $corrections = StructureInclusionCorrections::Published2023)
    {
    }

    public function validate(StructureTreeRoot $root, StructureTreeIndex $index): void
    {
        $resolver = new NamespaceRoleResolver($root->namespaces, $root->roleMap);
        $roles = new ResolvedStructureRoles();
        $standalone = true;
        foreach ($index->elements as $element) {
            $input = new StructureRole($element->type, $element->namespace);
            $role = $resolver->resolve($input);
            if ($role === null) {
                throw new \InvalidArgumentException('Every tagged structure role must resolve to a recognized namespace and type.');
            }
            $roles[$element] = $role;
            if ($input->namespaceName() !== StandardStructureNamespace::PDF17->value || $role->namespaceName() !== StandardStructureNamespace::PDF17->value) {
                $standalone = false;
            }
        }
        // Clause 5.2 expressly exempts standalone PDF 1.7 semantics from the mixed table.
        if ($standalone) {
            return;
        }
        if (count($root->kids()) !== 1 || $roles[$root->kids()[0]]->tableType() !== 'Document') {
            throw new \InvalidArgumentException('TS 32005 table 5 requires exactly one Document below StructTreeRoot.');
        }
        foreach ($index->elements as $element) {
            $role = $roles[$element];
            if ($role->namespaceName() === StandardStructureNamespace::PDF17->value && !in_array($role->type->value, self::PDF17_ONLY, true)) {
                throw new \InvalidArgumentException('A type common to PDF 1.7 and PDF 2.0 must use the PDF 2.0 namespace in a mixed structure tree (TS 32005, 5.3).');
            }
            if ($element->namespace === null && $root->roleMap->get($element->type) !== null) {
                throw new \InvalidArgumentException('A custom structure type requires an explicit namespace in a mixed tree (TS 32005, 5.6).');
            }
            $this->validateChildren($element, $role, $roles);
        }
    }

    /** @param \SplObjectStorage<StructureElement, StructureRole> $roles */
    private function validateChildren(StructureElement $element, StructureRole $role, \SplObjectStorage $roles): void
    {
        $name = $role->tableType();
        if ($role->namespaceName() === StandardStructureNamespace::MathML->value) {
            // MathML's grammar belongs to its namespace, not table 5.
            return;
        }
        $effective = $element;
        while (in_array($roles[$effective]->tableType(), self::TRANSPARENT, true) && $effective->parent() instanceof StructureElement) {
            $effective = $effective->parent();
        }
        $parentName = $roles[$effective]->tableType();
        $rules = StructureInclusionTable::RULES[$parentName]['children'] ?? [];
        $counts = [];
        $children = [];
        foreach ($element->kids() as $child) {
            if ($child instanceof StructureElement) {
                $childRole = $roles[$child];
                if ($childRole->namespaceName() === StandardStructureNamespace::MathML->value) {
                    if ($parentName !== 'Formula' || $childRole->type->value !== 'math') {
                        throw new \InvalidArgumentException('A MathML math root belongs under Formula.');
                    }
                    continue;
                }
                $childName = $childRole->tableType();
            } else {
                $childName = 'content item';
            }
            $children[] = $childName;
            $occurrence = $rules[$childName] ?? null;
            if ($this->corrections === StructureInclusionCorrections::Resolved336And440 && (($parentName === 'Code' && $childName === 'Sub') || ($parentName === 'WP' && $childName === 'Figure'))) {
                $occurrence = '0..n';
            }
            if ($occurrence === 'c') {
                throw new \LogicException('The supplied TS 32005 table 5 has an undefined c for WP/Figure; select the explicit issue 440 correction to use this relationship.');
            }
            if ($occurrence === null || $occurrence === 'ø') {
                throw new \InvalidArgumentException('TS 32005 table 5 forbids ' . $childName . ' below ' . $parentName . '.');
            }
            if ($occurrence === 'ø*' && !$this->grouping($effective, $roles)) {
                throw new \InvalidArgumentException('This relationship requires a grouping-level parent (TS 32005, table 4).');
            }
            $counts[$childName] = ($counts[$childName] ?? 0) + 1;
            if (in_array($occurrence, ['1', '0..1'], true) && $counts[$childName] > 1) {
                throw new \InvalidArgumentException('TS 32005 table 5 permits at most one ' . $childName . ' below ' . $parentName . '.');
            }
        }
        foreach ($rules as $childName => $occurrence) {
            if (in_array($occurrence, ['1', '1..n'], true) && !isset($counts[$childName])) {
                throw new \InvalidArgumentException('TS 32005 requires a child ' . $childName . ' below ' . $parentName . '.');
            }
        }
        if ($name === 'Ruby' && $children !== ['RB', 'RT'] && $children !== ['RB', 'RP', 'RT', 'RP'] && $children !== []) {
            throw new \InvalidArgumentException('Ruby requires RB/RT or RB/RP/RT/RP in logical order (14.8.4.7.3).');
        }
        if ($name === 'Warichu' && $children !== ['WT'] && $children !== ['WP', 'WT', 'WP'] && $children !== []) {
            throw new \InvalidArgumentException('Warichu requires WT, optionally enclosed by WP/WP (14.8.4.7.3).');
        }
        if (in_array($name, ['Link', 'Annot'], true)) {
            $parent = $element->parent();
            while ($parent instanceof StructureElement) {
                if ($roles[$parent]->tableType() === $name) {
                    throw new \InvalidArgumentException('Link and Annot cannot have an ancestor of the same role.');
                }
                $parent = $parent->parent();
            }
        }
    }

    /** @param \SplObjectStorage<StructureElement, StructureRole> $roles */
    private function grouping(StructureElement $element, \SplObjectStorage $roles): bool
    {
        foreach ($element->kids() as $child) {
            if (!$child instanceof StructureElement || in_array($roles[$child]->tableType(), ['Sub', 'Lbl', 'Em', 'Strong', 'Span', 'Quote', 'Reference', 'Ruby', 'RB', 'RT', 'RP', 'Warichu', 'WT', 'WP'], true)) {
                return false;
            }
        }
        return true;
    }
}
