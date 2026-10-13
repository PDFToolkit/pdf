<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** An explicitly drawn form XObject, ISO 12.5.5 and 8.10 (PDF pp.392–395). */
final class FormAppearanceStream extends StreamObject
{
    public function __construct(ContentStreams $contents, Rectangle $boundingBox, DictionaryType $resources, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Type', 'Subtype', 'BBox', 'Resources'], true)) {
                throw new \InvalidArgumentException('Appearance XObject structural entries are supplied by its constructor.');
            }
        }
        parent::__construct($contents, new DictionaryType(
            new Entry(new NameType('Type'), new NameType('XObject')),
            new Entry(new NameType('Subtype'), new NameType('Form')),
            new Entry(new NameType('BBox'), $boundingBox),
            new Entry(new NameType('Resources'), $resources),
            ...$entries,
        ));
    }
}
