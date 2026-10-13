<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;

/** Every code in a notdef range maps to the same CID, rather than incrementing it. */
final readonly class NotdefRange implements CidMapping
{
    public function __construct(public CMapCodeRange $range, public IntegerType $destination)
    {
        if ($destination->integer() < 0 || $destination->integer() > 65535) {
            throw new \InvalidArgumentException('A PDF 1.7 CID is between zero and 65535.');
        }
    }
    public function source(): CMapCodeRange
    {
        return $this->range;
    }
    public function operator(): string
    {
        return 'notdefrange';
    }
    public function line(): string
    {
        return $this->range->low->hex() . ' ' . $this->range->high->hex() . ' ' . $this->destination->integer();
    }
    public function notdef(): bool
    {
        return true;
    }
    public function cid(CMapCode $code): ?IntegerType
    {
        return $this->range->contains($code) ? $this->destination : null;
    }
}
