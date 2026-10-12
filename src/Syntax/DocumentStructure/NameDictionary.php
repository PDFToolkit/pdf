<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure;

use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** The catalog Names dictionary; every value is a name tree (table 31). */
final class NameDictionary extends DictionaryType
{
    public function __construct(Entry ...$entries)
    {
        foreach ($entries as $entry) {
            self::validate($entry);
        }
        parent::__construct(...$entries);
    }

    public function with(Entry $entry): static
    {
        self::validate($entry);

        return parent::with($entry);
    }

    private static function validate(Entry $entry): void
    {
        $value = $entry->value();
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if (!$target instanceof NameTree) {
            throw new \InvalidArgumentException('A names dictionary entry must contain a name tree.');
        }
    }
}
