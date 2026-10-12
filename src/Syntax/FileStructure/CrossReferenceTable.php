<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

final readonly class CrossReferenceTable implements \Stringable
{
    /** @var array<int, CrossReferenceEntry> */
    public array $entries;
    public function __construct(CrossReferenceEntry ...$entries)
    {
        $sorted = [];
        foreach ($entries as $entry) {
            if (isset($sorted[$entry->objectNumber])) {
                throw new \InvalidArgumentException('Duplicate cross-reference entry.');
            }
            $sorted[$entry->objectNumber] = $entry;
        }
        if (!isset($sorted[0]) || $sorted[0]->inUse || $sorted[0]->generation->value !== 65535) {
            throw new \InvalidArgumentException('Object zero must be free with generation 65535.');
        }
        ksort($sorted, SORT_NUMERIC);
        $this->entries = $sorted;
    }
    public function __toString(): string
    {
        $text = "xref\n";
        $start = null;
        $previous = -2;
        $group = [];
        foreach ($this->entries as $number => $entry) {
            if ($number !== $previous + 1 && $group !== []) {
                $text .= $start . ' ' . count($group) . "\n" . implode('', $group);
                $group = [];
            }
            if ($group === []) {
                $start = $number;
            }
            $group[] = (string) $entry;
            $previous = $number;
        }
        return $text . $start . ' ' . count($group) . "\n" . implode('', $group);
    }
}
