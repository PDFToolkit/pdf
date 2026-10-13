<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

/** Explicit discontiguous destination sequences, including ligatures (ISO pp.302–303). */
final readonly class UnicodeArrayRange implements UnicodeMapping
{
    /** @var list<UnicodeSequence> */
    public array $destinations;

    public function __construct(public CMapCodeRange $range, UnicodeSequence ...$destinations)
    {
        if ($destinations === [] || $range->high->number() - $range->low->number() !== (float) (count($destinations) - 1)) {
            throw new \InvalidArgumentException('A bfrange array contains exactly one destination per source code.');
        }
        $this->destinations = array_values($destinations);
    }
    public function source(): CMapCodeRange
    {
        return $this->range;
    }
    public function operator(): string
    {
        return 'bfrange';
    }
    public function line(): string
    {
        return $this->range->low->hex() . ' ' . $this->range->high->hex() . ' [' . implode(' ', array_map(static fn (UnicodeSequence $sequence) => $sequence->hex(), $this->destinations)) . ']';
    }
    public function sequence(CMapCode $code): ?UnicodeSequence
    {
        return !$this->range->contains($code) ? null : $this->destinations[(int) ($code->number() - $this->range->low->number())];
    }
}
