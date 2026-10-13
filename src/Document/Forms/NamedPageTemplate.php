<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** An invisible named page in the Templates name tree, ISO 12.7.6, PDF p.464. */
final class NamedPageTemplate extends DictionaryType
{
    public function __construct(Rectangle $mediaBox, DictionaryType $resources, ?StreamObject $contents = null, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Type', 'Parent', 'B', 'MediaBox', 'Resources', 'Contents'], true)) {
                throw new \InvalidArgumentException('Template pages have no Parent or B; structural entries are constructor-controlled.');
            }
        }
        $managed = [new Entry(new NameType('Type'), new NameType('Template')), new Entry(new NameType('MediaBox'), $mediaBox), new Entry(new NameType('Resources'), $resources)];
        if ($contents !== null) {
            $managed[] = new Entry(new NameType('Contents'), new IndirectReference($contents));
        }
        parent::__construct(...[...$managed, ...$entries]);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Template pages are constructed explicitly.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Template pages are constructed explicitly.');
    }
}
