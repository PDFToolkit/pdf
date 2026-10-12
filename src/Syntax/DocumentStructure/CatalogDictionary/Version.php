<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\NameType;

final class Version extends Entry
{
    public function __construct(NameType $version)
    {
        if (!preg_match('/^1\.[0-7]$/D', $version->value)) {
            throw new \InvalidArgumentException('A PDF 1.x catalog version must be between 1.0 and 1.7.');
        }
        parent::__construct(new NameType('Version'), $version);
    }
}
