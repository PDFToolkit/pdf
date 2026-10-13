<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\Fonts\Encodings\FontEncoding;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** 9.6.3: explicitly normalized name, byte widths and TrueType descriptor. */
final class TrueTypeFont extends SimpleFont
{
    public function __construct(NameType $baseFont, SimpleFontMetrics $metrics, Entry|FontEncoding|ToUnicodeCMap ...$values)
    {
        parent::__construct(FontSubtype::TrueType, $baseFont, $metrics, ...$values);
    }
}
