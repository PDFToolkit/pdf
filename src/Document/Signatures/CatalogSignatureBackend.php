<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\Object\DictionaryType;

/** An algorithm extension must be declared by the actual frozen document catalog. */
interface CatalogSignatureBackend extends SignatureBackend
{
    public function validateCatalog(DictionaryType $catalog): void;
}
