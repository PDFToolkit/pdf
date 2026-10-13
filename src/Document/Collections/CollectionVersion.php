<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;

/** Tables48–49 and155–158: portable collection objects were introduced in PDF1.7. */
trait CollectionVersion
{
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF17, 'Portable collection objects');
    }
}
