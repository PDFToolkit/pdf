<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\DictionaryType;

/** WTPDF6.2, physical p10: SHOULD is a recommendation, not an accessibility certificate. */
final readonly class WtPdfDeprecatedChecks
{
    public function __construct(private WtPdfDeprecatedCatalogue $catalogue = new WtPdfDeprecatedCatalogue(), private WtPdfDeprecatedLimits $limits = new WtPdfDeprecatedLimits())
    {
    }

    /** @return list<WtPdfFinding> */
    public function inspect(DictionaryType $catalog, ?WtPdfDeprecatedFileContext $file = null): array
    {
        return (new WtPdfDeprecatedTraversal($catalog, $file, $this->catalogue, $this->limits))->findings();
    }
}
