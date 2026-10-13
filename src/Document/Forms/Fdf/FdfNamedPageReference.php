<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Specification\Definition\FDFNamedPageReferenceDictionaryTable250Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** A page/template association, with no external file access (ISO p.472). */
final class FdfNamedPageReference extends SchemaDictionary
{
    public function __construct(StringType $name, StringType|DictionaryType|null $file = null)
    {
        $entries = [new Entry(new NameType('Name'), $name)];
        if ($file !== null) {
            $entries[] = new Entry(new NameType('F'), $file);
        }
        parent::__construct(new FDFNamedPageReferenceDictionaryTable250Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('FDF named page references are constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('FDF named page references are constructor-controlled.');
    }
}
