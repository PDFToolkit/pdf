<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;

readonly class CidRange implements CidMapping
{
    public function __construct(public CMapCodeRange $range, public IntegerType $first)
    {
        if ($first->integer() < 0 || $first->integer() > 65535) {
            throw new \InvalidArgumentException('A PDF 1.7 CID is between zero and 65535.');
        }
        $range->distance(65535 - $first->integer());
    }

    public function source(): CMapCodeRange
    {
        return $this->range;
    }
    public function operator(): string
    {
        return 'cidrange';
    }
    public function line(): string
    {
        return $this->range->low->hex() . ' ' . $this->range->high->hex() . ' ' . $this->first->integer();
    }
    public function notdef(): bool
    {
        return false;
    }
    public function cid(CMapCode $code): ?IntegerType
    {
        return !$this->range->contains($code) ? null : new IntegerType($this->first->integer() + (int) ($code->number() - $this->range->low->number()));
    }
}
