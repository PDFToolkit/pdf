<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;

final readonly class ASCIIHexDecode implements Filter
{
    public function name(): NameType
    {
        return new NameType('ASCIIHexDecode');
    }
    public function parameters(): ?DictionaryType
    {
        return null;
    }
    public function encode(string $bytes): string
    {
        return strtoupper(bin2hex($bytes)) . '>';
    }
}
