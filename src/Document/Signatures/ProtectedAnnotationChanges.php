<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Bounded P3 markup edits, ISO1 Table254 PDF479; ISO2 Table257 PDF589. */
final class ProtectedAnnotationChanges
{
    private const SUBTYPES = ['Text', 'FreeText', 'Line', 'Square', 'Circle', 'Polygon', 'PolyLine', 'Highlight', 'Underline', 'Squiggly', 'StrikeOut', 'Caret', 'Ink', 'Stamp'];
    private const EDITABLE = ['Rect', 'Contents', 'M', 'NM', 'F', 'C', 'T', 'CA', 'AP', 'AS', 'Border', 'BS', 'BE', 'IC', 'RD', 'L', 'LE', 'LL', 'LLE', 'LLO', 'Cap', 'CP', 'CO', 'Vertices', 'QuadPoints', 'InkList', 'Name', 'Sy', 'Q', 'DA', 'DS', 'CL', 'IT', 'Open', 'Subj'];
    /** @var list<string> */
    private array $operations = [];

    /** @param list<string> $instantiatedPages
     * @return list<string> create/modify/delete, used to intersect authenticated usage rights.
     */
    public function classify(ProtectedRevisionChanges $changes, SignaturePermissionHistory $history, array $instantiatedPages = []): array
    {
        $this->operations = [];
        $oldPages = $this->pages($changes->prior);
        $newPages = $this->pages($changes->current);
        foreach ($instantiatedPages as $id) {
            if (!isset($newPages[$id]) || isset($oldPages[$id]) || $newPages[$id]->has('Annots')) {
                throw new \LogicException('Only proved fresh template pages without annotations are excluded from annotation comparison.');
            }
            unset($newPages[$id]);
        }
        if (array_keys($oldPages) !== array_keys($newPages)) {
            throw new \LogicException('Protected annotation editing preserves the page tree and every page identity.');
        }
        $oldAnnotations = $this->annotations($changes->prior, $oldPages);
        $newAnnotations = $this->annotations($changes->current, $newPages);
        foreach ($oldPages as $id => $oldPage) {
            $newPage = $newPages[$id];
            $oldEntry = $oldPage->get('Annots');
            $newEntry = $newPage->get('Annots');
            $oldList = $this->annotationList($oldEntry, $changes->prior);
            $newList = $this->annotationList($newEntry, $changes->current);
            if (!$changes->sameDictionary($oldPage, $newPage, ['Annots'])) {
                throw new \LogicException('Annotation permission never authorizes changes to page content, geometry or resources.');
            }
            if (!$changes->same($oldList, $newList) || !$changes->same($oldEntry, $newEntry)) {
                $this->permission($history);
                $page = $this->pageIdentifier($id, $changes->current);
                $changes->change($page);
                if ($newEntry instanceof IndirectReference) {
                    $record = $changes->current->record($newEntry);
                    if ($oldEntry instanceof IndirectReference && (string) $changes->prior->record($oldEntry)->identifier === (string) $record->identifier) {
                        $changes->change($record->identifier);
                    } else {
                        $changes->create($record->identifier);
                    }
                    $changes->assertExclusiveReferences($record->identifier, [$id]);
                }
                // Existing annotations keep their relative stacking order. Widgets
                // cannot be inserted, removed or reordered under annotation permission.
                $before = $this->identities($oldList, $changes->prior);
                $after = $this->identities($newList, $changes->current);
                if (array_values(array_intersect($before, $after)) !== array_values(array_intersect($after, $before))) {
                    throw new \LogicException('Protected annotation edits preserve the order of surviving annotations.');
                }
            }
        }
        foreach ($oldAnnotations as $id => [$page, $entry, $dictionary, $owner]) {
            if (!isset($newAnnotations[$id])) {
                $this->permission($history);
                $this->modifiable($dictionary);
                $changes->assertExclusiveReferences($changes->prior->record($entry)->identifier, [$owner]);
                $this->operations[] = 'delete';
                continue; // Detach only; preserve the historical object and its bytes.
            }
            [$newPage, $newEntry, $replacement, $newOwner] = $newAnnotations[$id];
            if ($page !== $newPage) {
                throw new \LogicException('Protected annotation editing does not move ownership between pages.');
            }
            if ($changes->sameDictionary($dictionary, $replacement)) {
                continue;
            }
            // The form classifier owns widget AS/AP/V changes and proves its locks.
            $subtype = $dictionary->get('Subtype');
            if ($subtype instanceof NameType && $subtype->value === 'Widget') {
                continue;
            }
            $this->permission($history);
            $this->modifiable($dictionary);
            $this->modifiable($replacement);
            if (!$changes->sameDictionary($dictionary, $replacement, self::EDITABLE)) {
                throw new \LogicException('Annotation edits preserve subtype, relationships and all unrelated entries.');
            }
            $this->values($replacement, $changes, $newPage);
            $changes->appearance($replacement->get('AP'), $dictionary->get('AP'));
            $record = $changes->current->record($newEntry);
            $changes->assertExclusiveReferences($record->identifier, array_values(array_unique([$owner, $newOwner])));
            $changes->change($record->identifier);
            $this->operations[] = 'modify';
        }
        foreach ($newAnnotations as $id => [$page, $entry, $dictionary, $owner]) {
            if (isset($oldAnnotations[$id])) {
                continue;
            }
            $this->permission($history);
            $this->modifiable($dictionary);
            foreach ($dictionary->entries() as $item) {
                if (!in_array($item->key->value, [...self::EDITABLE, 'Type', 'Subtype', 'P'], true)) {
                    throw new \LogicException('A new protected annotation contains only classified markup entries.');
                }
            }
            $this->values($dictionary, $changes, $page);
            $changes->appearance($dictionary->get('AP'));
            $record = $changes->current->record($entry);
            $changes->assertExclusiveReferences($record->identifier, [$owner]);
            $changes->create($record->identifier);
            $this->operations[] = 'create';
        }
        return $this->operations;
    }

    private function permission(SignaturePermissionHistory $history): void
    {
        if ($history->permissions->integer() !== 3) {
            throw new \LogicException('Historical DocMDP/Adobe permissions below P3 forbid annotation edits.');
        }
    }

    private function modifiable(DictionaryType $dictionary): void
    {
        $type = $dictionary->get('Subtype');
        $flags = $dictionary->get('F');
        if (!$type instanceof NameType || !in_array($type->value, self::SUBTYPES, true)) {
            throw new \LogicException('This annotation subtype is outside the native protected markup classifier.');
        }
        if ($flags !== null && (!$flags instanceof IntegerType || ($flags->integer() & (64 | 128 | 512)) !== 0)) {
            throw new \LogicException('Protected editing preserves read-only, locked and locked-content annotations.');
        }
    }

    private function values(DictionaryType $dictionary, ProtectedRevisionChanges $changes, string $page): void
    {
        foreach (self::EDITABLE as $key) {
            $value = $dictionary->get($key);
            if ($value !== null && $key !== 'AP') {
                $changes->direct($value);
            }
        }
        $owner = $dictionary->get('P');
        if ($owner !== null && (!$owner instanceof IndirectReference || (string) $changes->current->record($owner)->identifier !== $page)) {
            throw new \LogicException('A classified annotation P identifies its actual page.');
        }
    }

    /** @return array<string, DictionaryType> */
    private function pages(FrozenSignatureGraph $graph): array
    {
        $pending = [$graph->catalog->get('Pages')];
        $seen = [];
        $pages = [];
        while ($pending !== []) {
            $entry = array_shift($pending);
            if (!$entry instanceof IndirectReference || count($seen) >= 4096) {
                throw new \LogicException('Protected page trees have bounded explicit indirect ownership.');
            }
            $record = $graph->record($entry);
            $id = (string) $record->identifier;
            if (isset($seen[$id])) {
                throw new \LogicException('Protected page trees have no repeated ownership.');
            }
            $seen[$id] = true;
            $dictionary = $record->dictionary ?? throw new \LogicException('Page tree entries are dictionaries.');
            $type = $dictionary->get('Type');
            if ($type instanceof NameType && $type->value === 'Page') {
                $pages[$id] = $dictionary;
            } elseif ($type instanceof NameType && $type->value === 'Pages' && ($kids = $graph->resolve($dictionary->get('Kids'))) instanceof ArrayType) {
                array_push($pending, ...$kids->items);
            } else {
                throw new \LogicException('Protected editing retains the actual page tree.');
            }
        }
        return $pages;
    }

    /** @param array<string, DictionaryType> $pages
     * @return array<string, array{string, IndirectReference, DictionaryType, string}>
     */
    private function annotations(FrozenSignatureGraph $graph, array $pages): array
    {
        $result = [];
        foreach ($pages as $page => $dictionary) {
            $entry = $dictionary->get('Annots');
            $owner = $entry instanceof IndirectReference ? (string) $graph->record($entry)->identifier : $page;
            foreach ($this->annotationList($entry, $graph)->items as $reference) {
                if (!$reference instanceof IndirectReference || count($result) >= 4096) {
                    throw new \LogicException('Protected annotations have bounded explicit indirect identities.');
                }
                $record = $graph->record($reference);
                $id = (string) $record->identifier;
                if (isset($result[$id])) {
                    throw new \LogicException('An annotation belongs to exactly one page.');
                }
                $result[$id] = [$page, $reference, $record->dictionary ?? throw new \LogicException('Annotations are dictionaries.'), $owner];
            }
        }
        return $result;
    }

    private function annotationList(?BasicType $entry, FrozenSignatureGraph $graph): ArrayType
    {
        $value = $graph->resolve($entry);
        return $value === null ? new ArrayType() : ($value instanceof ArrayType ? $value : throw new \LogicException('Page Annots is an array.'));
    }

    /** @return list<string> */
    private function identities(ArrayType $array, FrozenSignatureGraph $graph): array
    {
        return array_map(static fn (BasicType $entry): string => $entry instanceof IndirectReference ? (string) $graph->record($entry)->identifier : throw new \LogicException('Annotation identities are indirect.'), $array->items);
    }

    private function pageIdentifier(string $id, FrozenSignatureGraph $graph): \PDF\Syntax\Object\IndirectObject\ObjectIdentifier
    {
        foreach ($graph->revision->objects() as $record) {
            if ((string) $record->identifier === $id) {
                return $record->identifier;
            }
        }
        throw new \LogicException('The actual page identity is present.');
    }
}
