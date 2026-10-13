<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

final readonly class UnicodeScalar
{
    public function __construct(public IntegerType $value)
    {
        $point = $value->integer();
        if ($point < 0 || $point > 0x10ffff || ($point >= 0xd800 && $point <= 0xdfff)) {
            throw new \InvalidArgumentException('A Unicode scalar excludes surrogates and lies between U+0000 and U+10FFFF.');
        }
    }

    public function sequence(): UnicodeSequence
    {
        $point = $this->value->integer();
        return new UnicodeSequence(new HexadecimalString($point <= 0xffff ? pack('n', $point) : pack('nn', 0xd800 + (($point - 0x10000) >> 10), 0xdc00 + (($point - 0x10000) & 0x3ff))));
    }
}
