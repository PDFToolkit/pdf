<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Direct /Version name (ISO_32000-2_sponsored_EC3.pdf, Table 29, PDF p. 114). */
final class Version extends Entry
{
    public function __construct(NameType $version)
    {
        if (PdfVersion::tryFrom($version->value) === null) {
            throw new \InvalidArgumentException('A catalog version must identify a supported PDF version.');
        }
        parent::__construct(new NameType('Version'), $version);
    }
}
