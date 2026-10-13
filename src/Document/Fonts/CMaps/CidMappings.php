<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;

final readonly class CidMappings
{
    /** @var list<CidMapping> */
    public array $items;

    public function __construct(CidMapping ...$mappings)
    {
        $ordered = array_map(static fn (CidMapping $mapping) => [$mapping, $mapping->source()], $mappings);
        usort($ordered, static fn (array $left, array $right) => ($left[0]->notdef() <=> $right[0]->notdef()) ?: ($left[1]->low->length() <=> $right[1]->low->length()) ?: strcmp($left[1]->low->value->value, $right[1]->low->value->value));
        $previous = null;
        foreach ($ordered as [$mapping, $range]) {
            if (!in_array($mapping->operator(), ['cidchar', 'cidrange', 'notdefchar', 'notdefrange'], true)) {
                throw new \InvalidArgumentException('A CID encoding uses only CID and notdef mapping operators.');
            }
            if ($previous !== null && $previous[0]->notdef() === $mapping->notdef() && $previous[1]->overlaps($range)) {
                throw new \InvalidArgumentException('CID mappings must be unambiguous within their ordinary or notdef category.');
            }
            $previous = [$mapping, $range];
        }
        $this->items = array_values($mappings);
    }

    public function cid(CMapCode $code, bool $notdef = false): ?IntegerType
    {
        foreach ($this->items as $mapping) {
            if ($mapping->notdef() === $notdef && ($cid = $mapping->cid($code)) !== null) {
                return $cid;
            }
        }
        return null;
    }

    public function validate(CodeSpaces $spaces): void
    {
        foreach ($this->items as $mapping) {
            if (!$spaces->covers($mapping->source())) {
                throw new \InvalidArgumentException('Every code in a CID/notdef mapping must belong to its effective codespace.');
            }
        }
    }

    /** @return list<string> */
    public function program(): array
    {
        $lines = [];
        foreach (['cidchar', 'cidrange', 'notdefchar', 'notdefrange'] as $operator) {
            $mappings = array_values(array_filter($this->items, static fn (CidMapping $mapping) => $mapping->operator() === $operator));
            foreach (array_chunk($mappings, 100) as $chunk) {
                $lines[] = count($chunk) . ' begin' . $operator;
                array_push($lines, ...array_map(static fn (CidMapping $mapping) => $mapping->line(), $chunk));
                $lines[] = 'end' . $operator;
            }
        }
        return $lines;
    }
}
