<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Specification\Definition\FDFCatalogDictionaryTable242Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class FdfCatalog extends SchemaDictionary
{
    public function __construct(FdfFormData $data, ?NameType $version = null, Entry ...$entries)
    {
        if ($version !== null && !in_array($version->value, ['1.2', '1.3', '1.4', '1.5', '1.6', '1.7'], true)) {
            throw new \InvalidArgumentException('FDF catalogue versions follow PDF 1.2 through 1.7.');
        }
        $managed = [new Entry(new NameType('FDF'), $data)];
        if ($version !== null) {
            $managed[] = new Entry(new NameType('Version'), $version);
        }
        parent::__construct(new FDFCatalogDictionaryTable242Definition(), ...[...$managed, ...$entries]);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('FDF catalogue structure is constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('FDF catalogue structure is constructor-controlled.');
    }
}
