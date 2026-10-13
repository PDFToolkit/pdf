<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IntegerType;

/**
 * One complete initial index or sparse incremental section (ISO 32000-1, 7.5.4).
 *
 * New unused numbers are linked ahead of the previous free list. Previous numbers
 * are omitted unless supplied as changed objects. An explicit FreeListDelta
 * carries the validated lifecycle plan's changed historical links/generations.
 */
final readonly class CrossReferenceSection
{
    /** @var list<CrossReferenceStreamEntry> */
    private array $records;
    private IntegerType $head;

    public function __construct(
        public IntegerType $size,
        public IntegerType $firstNewObjectNumber = new IntegerType(1),
        public IntegerType $previousFreeListHead = new IntegerType(0),
        public CrossReferenceLimits $limits = new CrossReferenceLimits(),
        CrossReferenceStreamEntry|FreeListDelta ...$entries,
    ) {
        if ($size->value < 1 || $size->value > $limits->maximumEntries->value) {
            throw new \InvalidArgumentException('Cross-reference Size exceeds the configured entry capacity.');
        }
        if ($firstNewObjectNumber->value < 1 || $firstNewObjectNumber->value > $size->value) {
            throw new \InvalidArgumentException('The first new object number must lie between one and Size.');
        }
        if ($previousFreeListHead->value < 0 || $previousFreeListHead->value >= $firstNewObjectNumber->value) {
            throw new \InvalidArgumentException('The previous free-list head must refer to a previous object number.');
        }
        $records = [];
        foreach ($entries as $entry) {
            if ($entry instanceof FreeListDelta) {
                foreach ($entry->entries as $free) {
                    $number = $free->number();
                    if ($number >= $firstNewObjectNumber->value || isset($records[$number])) {
                        throw new \InvalidArgumentException('Explicit free-list changes affect unique historical numbers only.');
                    }
                    $records[$number] = $free;
                }
            }
        }
        foreach ($entries as $entry) {
            if ($entry instanceof FreeListDelta) {
                continue;
            }
            $number = $entry->number();
            if (!$entry instanceof CrossReferenceStreamInUseEntry && !$entry instanceof CrossReferenceStreamCompressedEntry) {
                throw new \InvalidArgumentException('A cross-reference section accepts only live object records.');
            }
            if ($number >= $size->value || isset($records[$number])) {
                throw new \InvalidArgumentException('Section object numbers must be unique and smaller than Size.');
            }
            if ($number === $previousFreeListHead->value) {
                throw new \InvalidArgumentException('The previous free-list head cannot become a live object.');
            }
            if ($entry instanceof CrossReferenceStreamInUseEntry && $entry->generation->value === 65535) {
                throw new \InvalidArgumentException('Generation 65535 is reserved for permanently free objects.');
            }
            if ($entry instanceof CrossReferenceStreamCompressedEntry && $entry->objectStreamNumber->value >= $size->value) {
                throw new \InvalidArgumentException('The object-stream number must be smaller than Size.');
            }
            $records[$number] = $entry;
        }
        $head = (int) $previousFreeListHead->value;
        // Walking backwards keeps the resulting free list ordered by object number.
        for ($number = (int) $size->value - 1; $number >= $firstNewObjectNumber->value; --$number) {
            if (isset($records[$number])) {
                continue;
            }
            $records[$number] = new CrossReferenceStreamFreeEntry(new IntegerType($number), new IntegerType($head));
            $head = $number;
        }
        $records[0] = new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType($head), new GenerationNumber(65535));
        ksort($records, SORT_NUMERIC);
        $this->records = array_values($records);
        $this->head = new IntegerType($head);
    }

    /** @return list<CrossReferenceStreamEntry> */
    public function entries(): array
    {
        return $this->records;
    }

    public function freeListHead(): IntegerType
    {
        return $this->head;
    }
}
