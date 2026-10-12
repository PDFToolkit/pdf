<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\PageDictionary;

use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Inheritable page media-box entry. */
final class MediaBox extends Entry
{
    public function __construct(Rectangle $rectangle)
    {
        parent::__construct(new NameType('MediaBox'), $rectangle);
    }
}
