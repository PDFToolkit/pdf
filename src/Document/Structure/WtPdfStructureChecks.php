<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Mechanical WTPDF structure checks; author intent remains an explicit human-review finding. */
final readonly class WtPdfStructureChecks
{
    public function __construct(private int $maximumTableSlots = 1000000)
    {
        if ($maximumTableSlots < 1) {
            throw new \InvalidArgumentException('Table validation requires a positive explicit slot budget.');
        }
    }

    /** @return list<WtPdfFinding> */
    public function inspect(StructureTreeRoot $root, WtPdfConformanceLevel $level): array
    {
        $findings = [];
        $fail = static function (string $clause, int $page, string $message, ?StructureElement $element = null) use (&$findings): void {
            $findings[] = new WtPdfFinding($clause, $page, WtPdfFindingStatus::Failed, $message, $element);
        };
        try {
            $index = $root->index();
            (new StructureInclusionRules($root->inclusionRules->corrections ?? StructureInclusionCorrections::Published2023))->validate($root, $index);
        } catch (\InvalidArgumentException|\LogicException $error) {
            $fail('6.2', 10, $error->getMessage());
            return $findings;
        }
        $resolver = new NamespaceRoleResolver($root->namespaces, $root->roleMap);
        $roles = new ResolvedStructureRoles();
        foreach ($index->elements as $element) {
            $role = $resolver->resolve(new StructureRole($element->type, $element->namespace));
            if ($role === null) {
                $fail('8.2.4', 16, 'The element does not resolve to a recognized standard structure namespace.', $element);
                continue;
            }
            $roles[$element] = $role;
        }
        if (count($roles) !== count($index->elements)) {
            return $findings; // No descendant/Ref lookup may assume that an unresolved role has an index entry.
        }
        $document = $root->kids()[0] ?? null;
        if (count($root->kids()) !== 1 || $document === null || !$roles->offsetExists($document) || $roles[$document]->type->value !== 'Document' || $document->namespace?->identifier !== StandardStructureNamespace::PDF20->value) {
            $fail('8.2.5.2', 17, 'The only root child must explicitly declare Document in the PDF 2.0 namespace.');
        }
        foreach ($root->namespaces->items as $namespace) {
            foreach ($namespace->roleMap->targets() as $target) {
                if ($target->namespaceName() === $namespace->identifier) {
                    $fail('8.2.4', 16, 'Role mappings within the same explicit namespace are forbidden by WTPDF.');
                }
            }
        }
        $attributes = new AttributeResolver($root->classMap, roles: $root->roleMap);
        foreach ($index->elements as $element) {
            if (!$roles->offsetExists($element)) {
                continue;
            }
            $name = $roles[$element]->type->value;
            if (($name === 'H' && $roles[$element]->namespaceName() !== StandardStructureNamespace::MathML->value) || ($name === 'Note' && $roles[$element]->namespaceName() === StandardStructureNamespace::PDF17->value)) {
                $fail($name === 'H' ? '8.2.5.12' : '8.2.5.14.1', $name === 'H' ? 19 : 20, 'WTPDF forbids the standard ' . $name . ' role.', $element);
            }
            $references = $element->get('Ref');
            if ($references !== null) {
                if (!$references instanceof ArrayType) {
                    $fail('8.8', 37, 'Ref must be an array of indirect structure-element references.', $element);
                } else {
                    foreach ($references->items as $reference) {
                        if (!$reference instanceof IndirectReference || !$reference->target() instanceof StructureElement || !in_array($reference->target(), $index->elements, true)) {
                            $fail('8.8', 37, 'Each Ref target must belong to this same structure tree.', $element);
                        } elseif ($name === 'FENote' || $roles[$reference->target()]->type->value === 'FENote') {
                            $reverse = $reference->target()->get('Ref');
                            if (!$reverse instanceof ArrayType || array_filter($reverse->items, static fn ($item): bool => $item instanceof IndirectReference && $item->target() === $element) === []) {
                                $fail('8.2.5.14.1', 20, 'FENote and its citing elements require reciprocal Ref associations.', $element);
                            }
                        }
                    }
                }
            }
            if ($level === WtPdfConformanceLevel::Accessibility && in_array($name, ['Figure', 'Formula'], true) && !$element->has('Alt') && !$element->has('ActualText')) {
                $fail($name === 'Figure' ? '8.2.5.28.2' : '8.2.5.29.2', $name === 'Figure' ? 26 : 27, 'An accessible illustration requires Alt or ActualText.', $element);
            }
            if ($name === 'Caption') {
                $parent = $element->parent();
                if ($parent instanceof StructureElement && $parent->kids()[0] !== $element && $parent->kids()[count($parent->kids()) - 1] !== $element) {
                    $fail('8.2.5.27', 25, 'A caption must be the first or last child of the content group that it captions.', $element);
                }
            }
            if ($name === 'Warichu') {
                $children = array_map(static fn ($kid): string => $kid instanceof StructureElement && $roles->offsetExists($kid) ? $roles[$kid]->type->value : 'content', $element->kids());
                if ($children !== ['WP', 'WT', 'WP']) {
                    $fail('8.2.5.24', 24, 'WTPDF Warichu requires WP, WT, WP in that order.', $element);
                }
            }
            if ($name === 'L') {
                $hasLabels = false;
                $queue = $element->kids();
                while ($queue !== []) {
                    $kid = array_pop($queue);
                    if ($kid instanceof StructureElement) {
                        $kidName = $roles[$kid]->type->value;
                        $hasLabels = $hasLabels || $kidName === 'Lbl';
                        if ($kidName !== 'L') {
                            array_push($queue, ...$kid->kids());
                        }
                    }
                }
                $numbering = $attributes->resolve($element, new NameType('List'), new NameType('ListNumbering'));
                if ($hasLabels && (!$attributes->hasExplicit($element, new NameType('List'), new NameType('ListNumbering')) || !$numbering instanceof NameType || $numbering->value === 'None')) {
                    $fail('8.2.5.25', 24, 'A labelled list requires an explicit ListNumbering other than None.', $element);
                }
                $continued = $attributes->resolve($element, new NameType('List'), new NameType('ContinuedList'));
                $from = $attributes->resolve($element, new NameType('List'), new NameType('ContinuedFrom'));
                if ($from !== null && (!$continued instanceof BooleanType || !$continued->value)) {
                    $fail('8.2.5.25', 24, 'ContinuedFrom requires ContinuedList=true.', $element);
                }
                if ($level === WtPdfConformanceLevel::Reuse && $continued instanceof BooleanType && $continued->value && !$references instanceof ArrayType) {
                    $fail('8.2.5.25', 24, 'A continued list requires Ref associations for reuse.', $element);
                }
            }
            if ($name === 'LI') {
                foreach ($element->kids() as $kid) {
                    if (!$kid instanceof StructureElement || !in_array($roles[$kid]->type->value, ['Lbl', 'LBody', 'Artifact', 'Div', 'NonStruct', 'Private'], true)) {
                        $fail('8.2.5.25', 24, 'Real list-item content must be enclosed in Lbl or LBody.', $element);
                    }
                }
            }
            if ($name === 'Table') {
                try {
                    $this->table($element, $roles, $attributes);
                } catch (\InvalidArgumentException|\OverflowException $error) {
                    $fail('8.2.5.26', 25, $error->getMessage(), $element);
                }
            }
        }
        return $findings;
    }

    /** @param \SplObjectStorage<StructureElement, StructureRole> $roles */
    private function table(StructureElement $table, \SplObjectStorage $roles, AttributeResolver $attributes): void
    {
        $groups = [[]];
        $queue = array_reverse($table->kids());
        while ($queue !== []) {
            $kid = array_pop($queue);
            if (!$kid instanceof StructureElement) {
                continue;
            }
            $role = $roles[$kid]->type->value;
            if ($role === 'TR') {
                $groups[0][] = $kid;
            } elseif (in_array($role, ['THead', 'TBody', 'TFoot'], true)) {
                $rows = [];
                $nested = array_reverse($kid->kids());
                while ($nested !== []) {
                    $row = array_pop($nested);
                    if ($row instanceof StructureElement && $roles[$row]->type->value === 'TR') {
                        $rows[] = $row;
                    } elseif ($row instanceof StructureElement && in_array($roles[$row]->type->value, ['Div', 'NonStruct', 'Private'], true)) {
                        array_push($nested, ...array_reverse($row->kids()));
                    }
                }
                $groups[] = $rows;
            } elseif (in_array($role, ['Div', 'NonStruct', 'Private'], true)) {
                array_push($queue, ...array_reverse($kid->kids()));
            }
        }
        $expectedWidth = null;
        $slots = 0;
        foreach ($groups as $rows) {
            $grid = [];
            foreach ($rows as $rowNumber => $row) {
                $column = 0;
                $cells = array_reverse($row->kids());
                while ($cells !== []) {
                    $cell = array_pop($cells);
                    if (!$cell instanceof StructureElement) {
                        continue;
                    }
                    $cellRole = $roles[$cell]->type->value;
                    if (in_array($cellRole, ['Div', 'NonStruct', 'Private'], true)) {
                        array_push($cells, ...array_reverse($cell->kids()));
                        continue;
                    }
                    if (!in_array($cellRole, ['TH', 'TD'], true)) {
                        continue;
                    }
                    while (isset($grid[$rowNumber][$column])) {
                        ++$column;
                    }
                    $rowSpan = $attributes->resolve($cell, new NameType('Table'), new NameType('RowSpan'));
                    $colSpan = $attributes->resolve($cell, new NameType('Table'), new NameType('ColSpan'));
                    $height = $rowSpan instanceof IntegerType ? $rowSpan->integer() : 1;
                    $width = $colSpan instanceof IntegerType ? $colSpan->integer() : 1;
                    if ($height < 1 || $width < 1 || $height > count($rows) - $rowNumber) {
                        throw new \InvalidArgumentException('Cell spans must be positive and may not cross a row-group boundary.');
                    }
                    if ($height > intdiv($this->maximumTableSlots - $slots, $width)) {
                        throw new \OverflowException('Table span expansion exceeds its explicit slot budget.');
                    }
                    $slots += $height * $width;
                    for ($r = $rowNumber; $r < $rowNumber + $height; ++$r) {
                        for ($c = $column; $c < $column + $width; ++$c) {
                            if (isset($grid[$r][$c])) {
                                throw new \InvalidArgumentException('Table cell spans overlap.');
                            }
                            $grid[$r][$c] = true;
                        }
                    }
                    $column += $width;
                }
            }
            foreach ($rows as $rowNumber => $_row) {
                $row = $grid[$rowNumber] ?? [];
                $width = $row === [] ? 0 : max(array_keys($row)) + 1;
                $expectedWidth ??= $width;
                if ($width !== $expectedWidth || count($row) !== $width) {
                    throw new \InvalidArgumentException('A table must have the same logical cell count in every row, accounting for cell spans.');
                }
            }
        }
    }
}
