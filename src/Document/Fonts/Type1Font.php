<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\Fonts\Encodings\FontEncoding;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Table 111: standard or explicitly named Type 1 font, with optional complete metrics. */
final class Type1Font extends SimpleFont
{
    public function __construct(StandardFont|NameType $baseFont, Entry|SimpleFontMetrics|FontEncoding|ToUnicodeCMap ...$values)
    {
        parent::__construct(FontSubtype::Type1, $baseFont instanceof StandardFont ? new NameType($baseFont->value) : $baseFont, ...$values);
    }
}
