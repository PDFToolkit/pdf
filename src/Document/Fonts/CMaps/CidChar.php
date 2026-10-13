<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;

/** begincidchar mapping, ISO 9.7.5.4, PDF pp.286–288. */
readonly class CidChar implements CidMapping
{
    public function __construct(public CMapCode $code, public IntegerType $destination)
    {
        if ($destination->integer() < 0 || $destination->integer() > 65535) {
            throw new \InvalidArgumentException('A PDF 1.7 CID is between zero and 65535.');
        }
    }

    public function source(): CMapCodeRange
    {
        return new CMapCodeRange($this->code, $this->code);
    }
    public function operator(): string
    {
        return 'cidchar';
    }
    public function line(): string
    {
        return $this->code->hex() . ' ' . $this->destination->integer();
    }
    public function notdef(): bool
    {
        return false;
    }
    public function cid(CMapCode $code): ?IntegerType
    {
        return $this->source()->contains($code) ? $this->destination : null;
    }
}
