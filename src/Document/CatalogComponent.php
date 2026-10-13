<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Syntax\Object\DictionaryType;

/** Validate relationships that become meaningful only in the actual document catalog. */
interface CatalogComponent
{
    public function validateCatalog(DictionaryType $catalog): void;
}
