<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

/** bfrange increments only the last destination byte, ISO PDF p.303. */
final readonly class UnicodeRange implements UnicodeMapping
{
    public function __construct(public CMapCodeRange $range, public UnicodeSequence $first)
    {
        $first->increment($range->distance(255));
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
        return $this->range->low->hex() . ' ' . $this->range->high->hex() . ' ' . $this->first->hex();
    }
    public function sequence(CMapCode $code): ?UnicodeSequence
    {
        return !$this->range->contains($code) ? null : $this->first->increment((int) ($code->number() - $this->range->low->number()));
    }
}
