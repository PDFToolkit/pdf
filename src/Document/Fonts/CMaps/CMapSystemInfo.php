<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Dictionary\CIDSystemInfoDictionaryTable116;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\DataStructure\AsciiString;

/** Explicit CID collection metadata, ISO 9.7.3, PDF p.278. */
final readonly class CMapSystemInfo
{
    public function __construct(public LiteralString $registry, public LiteralString $ordering, public IntegerType $supplement)
    {
        if ($supplement->integer() < 0) {
            throw new \InvalidArgumentException('A CID collection supplement is nonnegative.');
        }
        new AsciiString($registry->value);
        new AsciiString($ordering->value);
    }

    public function dictionary(): CIDSystemInfoDictionaryTable116
    {
        return new CIDSystemInfoDictionaryTable116(new Entry(new NameType('Registry'), $this->registry), new Entry(new NameType('Ordering'), $this->ordering), new Entry(new NameType('Supplement'), $this->supplement));
    }

    public function program(): string
    {
        return $this->dictionary()->toPdf(new SerializationContext());
    }
}
