<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** 9.9: the complete CID TrueType glyph program, with its uncompressed Length1. */
final class FontFile2 extends FontStream
{
    public function __construct(public readonly CIDFontProgram $program, Filter ...$filters)
    {
        parent::__construct($program->bytes, new DictionaryType(new Entry(new NameType('Length1'), new IntegerType(strlen($program->bytes)))), ...$filters);
    }
}
