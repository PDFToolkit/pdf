<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** Resource dictionary (ISO 32000-1, 7.8.3 and table 33). */
final class ResourceDictionary extends DictionaryType
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
        if ($entry->key()->value === 'ProcSet') {
            if (!$target instanceof ArrayType) {
                throw new \InvalidArgumentException('ProcSet must be an array.');
            }
        } elseif (in_array($entry->key()->value, ['ExtGState', 'ColorSpace', 'Pattern', 'Shading', 'XObject', 'Font', 'Properties'], true) && !$target instanceof DictionaryType) {
            throw new \InvalidArgumentException('A named resource entry must contain a dictionary.');
        }
    }
}
