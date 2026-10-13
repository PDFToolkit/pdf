<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

final readonly class UnicodeMappings
{
    /** @var list<UnicodeMapping> */
    public array $items;

    public function __construct(UnicodeMapping ...$mappings)
    {
        $ordered = array_map(static fn (UnicodeMapping $mapping) => [$mapping, $mapping->source()], $mappings);
        usort($ordered, static fn (array $left, array $right) => ($left[1]->low->length() <=> $right[1]->low->length()) ?: strcmp($left[1]->low->value->value, $right[1]->low->value->value));
        $previous = null;
        foreach ($ordered as [$mapping, $range]) {
            if (!in_array($mapping->operator(), ['bfchar', 'bfrange'], true)) {
                throw new \InvalidArgumentException('A ToUnicode map uses only bfchar and bfrange operators.');
            }
            if ($previous !== null && $previous->overlaps($range)) {
                throw new \InvalidArgumentException('ToUnicode mappings must be unambiguous within one CMap.');
            }
            $previous = $range;
        }
        $this->items = array_values($mappings);
    }

    public function sequence(CMapCode $code): ?UnicodeSequence
    {
        foreach ($this->items as $mapping) {
            if (($sequence = $mapping->sequence($code)) !== null) {
                return $sequence;
            }
        }
        return null;
    }

    public function validate(CodeSpaces $spaces): void
    {
        foreach ($this->items as $mapping) {
            if (!$spaces->covers($mapping->source())) {
                throw new \InvalidArgumentException('Every ToUnicode source code belongs to its effective codespace.');
            }
        }
    }

    /** @return list<string> */
    public function program(): array
    {
        $lines = [];
        foreach (['bfchar', 'bfrange'] as $operator) {
            $mappings = array_values(array_filter($this->items, static fn (UnicodeMapping $mapping) => $mapping->operator() === $operator));
            foreach (array_chunk($mappings, 100) as $chunk) {
                $lines[] = count($chunk) . ' begin' . $operator;
                array_push($lines, ...array_map(static fn (UnicodeMapping $mapping) => $mapping->line(), $chunk));
                $lines[] = 'end' . $operator;
            }
        }
        return $lines;
    }
}
