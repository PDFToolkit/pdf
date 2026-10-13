<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;

/** Prior classic fallback and current update table, ISO7.5.8.4; hidden generations cannot be reused. */
final readonly class HybridCrossReferenceSection
{
    public function __construct(public CrossReferenceSection $section, public ObjectNumber $auxiliary)
    {
        if ($auxiliary->value >= $section->size->integer()) {
            throw new \InvalidArgumentException('The auxiliary xref stream must belong to the section Size.');
        }
    }

    public function fallback(): CrossReferenceTable
    {
        $records = [];
        $free = [];
        foreach ($this->section->entries() as $entry) {
            if ($entry->number() === 0) {
                continue;
            }
            if ($entry instanceof CrossReferenceStreamFreeEntry) {
                $free[$entry->number()] = $entry->generation;
            } elseif ($entry instanceof CrossReferenceStreamCompressedEntry || $entry->number() === $this->auxiliary->value) {
                $free[$entry->number()] = new GenerationNumber(65535);
            } elseif ($entry instanceof CrossReferenceStreamInUseEntry) {
                $records[] = new CrossReferenceEntry($entry->number(), $entry->byteOffset, $entry->generation, true);
            }
        }
        krsort($free, SORT_NUMERIC);
        $head = $this->section->previousFreeListHead->integer();
        foreach ($free as $number => $generation) {
            $records[] = new CrossReferenceEntry($number, new ByteOffset($head), $generation, false);
            $head = $number;
        }
        return new CrossReferenceTable(new CrossReferenceEntry(0, new ByteOffset($head), new GenerationNumber(65535), false), ...$records);
    }

    public function update(): CrossReferenceTable
    {
        // Actual free numbers differ from the permanently hidden classic view.
        // This current entry also prevents a modern reader following a free-list
        // head that resolves to a live compressed object through XRefStm.
        return new CrossReferenceTable(new CrossReferenceEntry(0, new ByteOffset($this->section->freeListHead()->integer()), new GenerationNumber(65535), false));
    }
}
