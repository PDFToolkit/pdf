<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class CreatorUsage extends UsageEntry
{
    public function __construct(TextString $creator, NameType $subtype, Entry ...$extensions)
    {
        foreach ($extensions as $entry) {
            if (in_array($entry->key->value, ['Creator', 'Subtype'], true)) {
                throw new \InvalidArgumentException('Creator usage structural entries are explicit constructor values.');
            }
        }
        parent::__construct(UsageCategory::CreatorInfo, new Entry(new NameType('Creator'), $creator), new Entry(new NameType('Subtype'), $subtype), ...$extensions);
    }
}
