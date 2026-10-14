<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Document\Navigation\DestinationLookup;
use PDF\Document\Navigation\StructureDestinationResolver;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** WTPDF 8.8, physical pages 36–37: declared internal targets use SD. */
final readonly class WtPdfNavigationChecks
{
    public function __construct(private int $maximumValues = 100000)
    {
        if ($maximumValues < 1) {
            throw new \InvalidArgumentException('Navigation auditing requires a positive traversal budget.');
        }
    }

    /** @return list<WtPdfFinding> */
    public function inspect(DictionaryType $catalog): array
    {
        $findings = [];
        $pending = [[$catalog, 'catalog']];
        $seen = [];
        $resolver = null;
        $lookup = new DestinationLookup();
        $target = function (?BasicType $fallback, ?BasicType $sd) use ($catalog, &$resolver, $lookup, &$findings): void {
            $fallback = $this->value($fallback);
            $sd = $this->value($sd);
            if (!$fallback instanceof ArrayType && !$fallback instanceof NameType && !$fallback instanceof StringType) {
                $findings[] = new WtPdfFinding('8.8', 36, WtPdfFindingStatus::Failed, 'A structure destination keeps its required D page fallback or named page destination.');
                return;
            }
            if ($sd === null && ($fallback instanceof NameType || $fallback instanceof StringType)) {
                $sd = $lookup->structure($fallback, $catalog);
            }
            if (!$sd instanceof ArrayType) {
                $findings[] = new WtPdfFinding('8.8', 36, WtPdfFindingStatus::Failed, 'An internal destination requires a structure destination, with a D page fallback kept separately.');
                return;
            }
            ($resolver ??= new StructureDestinationResolver($catalog))->page($sd);
        };
        while ($pending !== []) {
            [$object, $kind] = array_pop($pending);
            $object = $this->value($object);
            if ($object === null) {
                continue;
            }
            $key = spl_object_id($object) . ':' . $kind;
            if (isset($seen[$key])) {
                continue;
            }
            if (count($seen) >= $this->maximumValues) {
                throw new \OverflowException('WTPDF destination inspection exceeds its explicit budget.');
            }
            $seen[$key] = true;
            if ($object instanceof ArrayType) {
                if ($kind === 'open' || $kind === 'destination') {
                    $target($object, null);
                }
                foreach ($object->items as $index => $item) {
                    if ($kind === 'pairs' && $index % 2 === 0) {
                        continue;
                    }
                    $pending[] = [$item, match ($kind) {
                        'pairs' => 'destination', 'annotations' => 'annotation', 'fields' => 'field', 'action' => 'action', 'nameTree' => 'nameTree', default => 'other'
                    }];
                }
                continue;
            }
            if (!$object instanceof DictionaryType) {
                continue;
            }
            $type = $this->value($object->get('Type'));
            $subtype = $this->value($object->get('Subtype'));
            $annotation = $kind === 'annotation' || ($type instanceof NameType && $type->value === 'Annot');
            $field = $kind === 'field' || $this->value($object->get('FT')) instanceof NameType;
            $node = $kind === 'node' || ($type instanceof NameType && $type->value === 'NavNode');
            $action = in_array($kind, ['action', 'open'], true) || ($type instanceof NameType && $type->value === 'Action');
            if ($kind === 'destination') {
                $target($object->get('D'), $object->get('SD'));
            }
            if ($action) {
                $actionType = $this->value($object->get('S'));
                if ($actionType instanceof NameType && $actionType->value === 'GoTo') {
                    $target($object->get('D'), $object->get('SD'));
                }
            }
            if (($kind === 'outline' || ($annotation && $subtype instanceof NameType && $subtype->value === 'Link')) && $object->has('Dest')) {
                $target($object->get('Dest'), null);
            }
            foreach ($object->entries() as $entry) {
                $childKind = match (true) {
                    $kind === 'legacy' => 'destination',
                    $kind === 'nameTree' && $entry->key->value === 'Names' => 'pairs',
                    $kind === 'nameTree' && $entry->key->value === 'Kids' => 'nameTree',
                    $kind === 'catalog' && $entry->key->value === 'Dests' => 'legacy',
                    $kind === 'catalog' && $entry->key->value === 'Names' => 'names',
                    $kind === 'names' && $entry->key->value === 'Dests' => 'nameTree',
                    $entry->key->value === 'Annots' => 'annotations',
                    (($annotation || $field || $kind === 'outline') && $entry->key->value === 'A') || ($action && $entry->key->value === 'Next') || ($node && in_array($entry->key->value, ['NA', 'PA'], true)) || $kind === 'additional' => 'action',
                    ($annotation || $field || $kind === 'catalog' || ($type instanceof NameType && $type->value === 'Page')) && $entry->key->value === 'AA' => 'additional',
                    ($type instanceof NameType && $type->value === 'Page' && $entry->key->value === 'PresSteps') || ($node && in_array($entry->key->value, ['Next', 'Prev'], true)) => 'node',
                    $kind === 'catalog' && $entry->key->value === 'AcroForm' => 'acroform',
                    ($kind === 'acroform' && $entry->key->value === 'Fields') || ($field && $entry->key->value === 'Kids') => 'fields',
                    $kind === 'catalog' && $entry->key->value === 'OpenAction' => 'open',
                    $kind === 'catalog' && $entry->key->value === 'Outlines' => 'outline',
                    $kind === 'outline' && in_array($entry->key->value, ['First', 'Last', 'Next', 'Prev'], true) => 'outline',
                    default => 'other',
                };
                $pending[] = [$entry->value, $childKind];
            }
        }
        return $findings;
    }

    private function value(?BasicType $value): ?BasicType
    {
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        return $target instanceof NullType ? null : $target;
    }
}
