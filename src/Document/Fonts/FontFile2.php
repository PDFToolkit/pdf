<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** 9.9: a supplied TrueType program with its uncompressed Length1; no sfnt parsing. */
final class FontFile2 extends FontStream
{
    public function __construct(string $bytes, Filter ...$filters)
    {
        parent::__construct($bytes, new DictionaryType(new Entry(new NameType('Length1'), new IntegerType(strlen($bytes)))), ...$filters);
    }
}
