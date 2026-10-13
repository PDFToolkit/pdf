<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\Fonts\Encodings\FontEncoding;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** 9.6.2.3: a supplied Type 1 snapshot of one multiple-master instance. */
final class MMType1Font extends SimpleFont
{
    public function __construct(NameType $instancePostScriptName, SimpleFontMetrics $metrics, Entry|FontEncoding|ToUnicodeCMap ...$values)
    {
        parent::__construct(FontSubtype::MMType1, new NameType(str_replace(' ', '_', $instancePostScriptName->value)), $metrics, ...$values);
    }
}
