<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Specification\Dictionary\CryptFiltersTable14;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** ISO 7.4.10: selection is applied by the file security operation after encoding. */
final readonly class Crypt implements Filter
{
    public function __construct(public NameType $selection = new NameType('Identity'))
    {
    }

    public function name(): NameType
    {
        return new NameType('Crypt');
    }

    public function parameters(): DictionaryType
    {
        return new CryptFiltersTable14(
            new Entry(new NameType('Type'), new NameType('CryptFilterDecodeParms')),
            new Entry(new NameType('Name'), $this->selection),
        );
    }

    public function encode(string $bytes): string
    {
        return $bytes;
    }
}
