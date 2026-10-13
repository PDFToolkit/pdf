<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Specification\Definition\ReferenceDictionaryTable97Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Weak external page reference, with optional two-string identifier; no external file is read. */
final class ReferenceDictionary extends SchemaDictionary implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF14, 'Reference XObject dictionary');
    }

    public const string TABLE = '97';

    public function __construct(StringType|DictionaryType $file, public readonly IntegerType|TextString $page, ?ArrayType $identifier = null)
    {
        if ($page instanceof IntegerType && $page->integer() < 0) {
            throw new \InvalidArgumentException('External page indexes are zero-based and nonnegative.');
        }
        if ($identifier !== null && (count($identifier) !== 2 || !$identifier->items[0] instanceof StringType || !$identifier->items[1] instanceof StringType)) {
            throw new \InvalidArgumentException('External file ID must contain two byte strings.');
        }
        $entries = [new Entry(new NameType('F'), $file), new Entry(new NameType('Page'), $page)];
        if ($identifier !== null) {
            $entries[] = new Entry(new NameType('ID'), $identifier);
        }
        parent::__construct(new ReferenceDictionaryTable97Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Reference file/page/ID declarations are constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Reference file/page/ID declarations are constructor-controlled.');
    }
}
