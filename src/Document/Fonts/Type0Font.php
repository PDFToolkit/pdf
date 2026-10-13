<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\Fonts\TrueType\ToUnicodeCMap;
use PDF\Specification\Definition\Type0FontDictionaryTable121Definition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Table 121: Identity-H composite font, with an explicit Unicode extraction map. */
final class Type0Font extends ManagedFontDictionary
{
    public const string TABLE = '121';

    public function __construct(public readonly CIDFontType2 $descendantFont, ToUnicodeCMap $toUnicode, Entry ...$entries)
    {
        if ($descendantFont->map !== $toUnicode->map) {
            throw new \InvalidArgumentException('The descendant font and ToUnicode must use the same repertoire.');
        }
        $managed = ['Type', 'Subtype', 'BaseFont', 'Encoding', 'DescendantFonts', 'ToUnicode'];
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, $managed, true)) {
                throw new \InvalidArgumentException('The Type 0 font manages /' . $entry->key()->value . '.');
            }
        }
        parent::__construct(
            new Type0FontDictionaryTable121Definition(),
            $managed,
            new Entry(new NameType('Type'), new NameType('Font')),
            new Entry(new NameType('Subtype'), new NameType('Type0')),
            new Entry(new NameType('BaseFont'), new NameType($descendantFont->map->program->postScriptName)),
            new Entry(new NameType('Encoding'), new NameType('Identity-H')),
            new Entry(new NameType('DescendantFonts'), new ArrayType(new IndirectReference($descendantFont))),
            new Entry(new NameType('ToUnicode'), new IndirectReference($toUnicode)),
            ...$entries,
        );
    }
}
