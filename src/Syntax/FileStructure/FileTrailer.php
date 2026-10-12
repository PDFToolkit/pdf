<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;

class FileTrailer extends DictionaryType
{
    public function __construct(IntegerType $size, IndirectReference $root, Entry ...$entries)
    {
        if ($size->value < 1) {
            throw new \InvalidArgumentException('Trailer Size must be positive.');
        }
        foreach ($entries as $entry) {
            self::validateEntry($entry);
        }
        parent::__construct(new Entry(new NameType('Size'), $size), new Entry(new NameType('Root'), $root), ...$entries);
    }

    public function with(Entry $entry): static
    {
        if (in_array($entry->key->value, ['Size', 'Root'], true)) {
            throw new \InvalidArgumentException('Required trailer fields cannot be replaced.');
        }
        self::validateEntry($entry);
        return parent::with($entry);
    }

    public function without(NameType|string $key): static
    {
        if (in_array($key instanceof NameType ? $key->value : $key, ['Size', 'Root'], true)) {
            throw new \InvalidArgumentException('Required trailer fields cannot be removed.');
        }
        return parent::without($key);
    }

    private static function validateEntry(Entry $entry): void
    {
        $name = $entry->key->value;
        $value = $entry->value;
        if (in_array($name, ['Info', 'Encrypt'], true) && !$value instanceof IndirectReference) {
            throw new \InvalidArgumentException('Trailer ' . $name . ' must be an indirect reference.');
        }
        if (in_array($name, ['Prev', 'XRefStm'], true) && (!$value instanceof IntegerType || $value->value < 0)) {
            throw new \InvalidArgumentException('Trailer ' . $name . ' must be a direct non-negative integer.');
        }
        if ($name === 'ID' && (!$value instanceof ArrayType || count($value->items) !== 2 || !$value->items[0] instanceof StringType || !$value->items[1] instanceof StringType)) {
            throw new \InvalidArgumentException('Trailer ID must contain exactly two byte strings.');
        }
    }
}
