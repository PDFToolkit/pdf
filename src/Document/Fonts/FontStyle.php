<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** 9.8.3.2: the currently defined CID font style entry is Panose. */
final class FontStyle extends DictionaryType
{
    public function __construct(Panose $panose)
    {
        parent::__construct(new Entry(new NameType('Panose'), $panose));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('FontStyle is assembled from its explicit Panose value.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('FontStyle is assembled from its explicit Panose value.');
    }
}
