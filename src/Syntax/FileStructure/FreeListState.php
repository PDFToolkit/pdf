<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;

/** Complete effective type-0 entries, excluding hybrid compatibility shadows of live objects (7.5.4, PDF p49). */
final readonly class FreeListState
{
    /** @var array<int, CrossReferenceStreamFreeEntry> */
    private array $records;

    public function __construct(public IntegerType $size, CrossReferenceStreamFreeEntry ...$entries)
    {
        if ($size->value < 1) {
            throw new \InvalidArgumentException('A free-list index has a positive Size.');
        }
        $records = [];
        foreach ($entries as $entry) {
            $number = $entry->number();
            if ($number >= $size->value || $entry->nextFreeObjectNumber->value >= $size->value || isset($records[$number])) {
                throw new \InvalidArgumentException('Free-list entries are unique and their numbers/links lie below Size.');
            }
            $records[$number] = $entry;
        }
        if (!isset($records[0])) {
            throw new \InvalidArgumentException('The effective free list includes its permanent object-zero head.');
        }
        $seen = [0 => true];
        $number = $records[0]->nextFreeObjectNumber->integer();
        while ($number !== 0) {
            if (!isset($records[$number]) || isset($seen[$number])) {
                throw new \InvalidArgumentException('A free-list chain terminates at zero without cycles or live links.');
            }
            $seen[$number] = true;
            $number = $records[$number]->nextFreeObjectNumber->integer();
        }
        foreach ($records as $number => $entry) {
            if (!isset($seen[$number]) && ($entry->generation->value !== 65535 || $entry->nextFreeObjectNumber->integer() !== 0)) {
                throw new \InvalidArgumentException('Only permanently unavailable generation-65535 entries may lie outside the free chain.');
            }
        }
        ksort($records, SORT_NUMERIC);
        $this->records = $records;
    }

    /** @return list<CrossReferenceStreamFreeEntry> */
    public function entries(): array
    {
        return array_values($this->records);
    }

    public function head(): IntegerType
    {
        return $this->records[0]->nextFreeObjectNumber;
    }

    public function entry(ObjectNumber $number): CrossReferenceStreamFreeEntry
    {
        return $this->records[$number->value] ?? throw new \OutOfBoundsException('The object number is not free in this revision.');
    }

    public function released(RevisionObject ...$objects): self
    {
        $records = $this->records;
        foreach ($objects as $object) {
            $number = $object->identifier->objectNumber->value;
            if ($number >= $this->size->value || isset($records[$number])) {
                throw new \LogicException('Only an existing live object number can be released once.');
            }
            // Increment on deletion, never on allocation of an unused hole.
            $records[$number] = new CrossReferenceStreamFreeEntry(new IntegerType($number), new IntegerType(0), $object->identifier->generationNumber->increment());
        }
        return $this->linked($records);
    }

    public function reused(ObjectNumber ...$numbers): self
    {
        $records = $this->records;
        foreach ($numbers as $number) {
            $entry = $records[$number->value] ?? throw new \OutOfBoundsException('Only an existing free number can be reused.');
            if ($entry->generation->value === 65535) {
                throw new \LogicException('Generation 65535 can never be reused.');
            }
            unset($records[$number->value]);
        }
        return $this->linked($records);
    }

    public function withSection(IntegerType $size, CrossReferenceStreamEntry ...$entries): self
    {
        if ($size->value < $this->size->value) {
            throw new \LogicException('An incremental Size never shrinks.');
        }
        $records = $this->records;
        foreach ($entries as $entry) {
            if ($entry instanceof CrossReferenceStreamFreeEntry) {
                $records[$entry->number()] = $entry;
            } else {
                unset($records[$entry->number()]);
            }
        }
        return new self($size, ...array_values($records));
    }

    public function changesFrom(self $previous): FreeListDelta
    {
        $changed = [];
        foreach ($this->records as $number => $record) {
            if (!isset($previous->records[$number]) || $record->fields() !== $previous->records[$number]->fields()) {
                $changed[] = $record;
            }
        }
        return new FreeListDelta(...$changed);
    }

    /** @param array<int, CrossReferenceStreamFreeEntry> $records */
    private function linked(array $records): self
    {
        unset($records[0]);
        krsort($records, SORT_NUMERIC);
        $next = 0;
        foreach ($records as $number => $record) {
            $records[$number] = new CrossReferenceStreamFreeEntry(new IntegerType($number), new IntegerType($next), $record->generation);
            $next = $number;
        }
        $records[0] = new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType($next), new GenerationNumber(65535));
        return new self($this->size, ...array_values($records));
    }
}
