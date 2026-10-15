<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Filter;
use PDF\Document\Fonts\CMaps\CMapCode;
use PDF\Document\Fonts\CMaps\CMapName;
use PDF\Document\Fonts\CMaps\CodeSpaces;
use PDF\Document\Fonts\CMaps\CodeSpaceRange;
use PDF\Document\Fonts\CMaps\UnicodeCMap;
use PDF\Document\Fonts\CMaps\UnicodeCMapResource;
use PDF\Document\Fonts\CMaps\UnicodeChar;
use PDF\Document\Fonts\CMaps\UnicodeMappings;
use PDF\Document\Fonts\CMaps\UnicodeScalar;
use PDF\Document\Fonts\CMaps\UnicodeSequence;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** 9.10.3: character codes map to UTF-16BE, including surrogate pairs. */
final class ToUnicodeCMap extends FontStream implements UnicodeCMapResource
{
    public readonly UnicodeCMap $definition;

    public function __construct(public readonly CharacterMap|UnicodeCMap $map, Filter ...$filters)
    {
        if ($map instanceof CharacterMap) {
            $map = new UnicodeCMap(
                new CMapName(new NameType('Adobe-Identity-UCS')),
                new CodeSpaces(new CodeSpaceRange(new CMapCode(new HexadecimalString("\0\0")), new CMapCode(new HexadecimalString("\xff\xff")))),
                new UnicodeMappings(...array_map(static fn (CharacterMapping $mapping) => new UnicodeChar(new CMapCode(new HexadecimalString(pack('n', $mapping->cid))), (new UnicodeScalar(new IntegerType($mapping->codePoint)))->sequence()), $map->mappings)),
            );
        }
        $this->definition = $map;
        $dictionary = new DictionaryType(
            new Entry(new NameType('Type'), new NameType('CMap')),
            new Entry(new NameType('CMapName'), $map->name->value),
            new Entry(new NameType('CIDSystemInfo'), $map->systemInfo->dictionary()),
            new Entry(new NameType('WMode'), new IntegerType($map->mode->value)),
        );
        if ($map->base !== null) {
            $dictionary = $dictionary->with(new Entry(new NameType('UseCMap'), $map->base->object()));
        }
        parent::__construct($map->program(), $dictionary, ...$filters);
    }

    public function object(): IndirectReference
    {
        return new IndirectReference($this);
    }
    public function name(): CMapName
    {
        return $this->definition->name;
    }
    public function codeSpaces(): CodeSpaces
    {
        return $this->definition->effectiveSpaces;
    }
    public function sequence(CMapCode $code): ?UnicodeSequence
    {
        return $this->definition->sequence($code);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        parent::validateVersion($version);
        $version->require(PdfVersion::PDF12, 'ToUnicode CMap');
    }
}
