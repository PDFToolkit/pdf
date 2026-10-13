<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StreamObject;

/** Font data starts decoded; filters must actually encode the supplied bytes. */
abstract class FontStream extends StreamObject implements VersionedComponent
{
    protected function __construct(string $bytes, ?DictionaryType $dictionary, Filter ...$filters)
    {
        foreach ($filters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('Font streams supply decoded bytes and cannot use EncodedData.');
            }
        }
        parent::__construct($bytes, $dictionary, ...$filters);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        if ($this->dictionary()->has('Metadata')) {
            $version->require(PdfVersion::PDF14, 'Font program Metadata');
        }
    }
}
