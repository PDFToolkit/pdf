<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

final readonly class UnicodeChar implements UnicodeMapping
{
    public function __construct(public CMapCode $code, public UnicodeSequence $destination)
    {
    }
    public function source(): CMapCodeRange
    {
        return new CMapCodeRange($this->code, $this->code);
    }
    public function operator(): string
    {
        return 'bfchar';
    }
    public function line(): string
    {
        return $this->code->hex() . ' ' . $this->destination->hex();
    }
    public function sequence(CMapCode $code): ?UnicodeSequence
    {
        return $this->source()->contains($code) ? $this->destination : null;
    }
}
