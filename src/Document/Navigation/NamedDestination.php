<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\CatalogComponent;
use PDF\Document\DocumentGraphContext;
use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Local named reference with explicit namespace and actual catalogue lookup. */
final readonly class NamedDestination implements CatalogComponent, VersionedComponent
{
    public function __construct(public NameType|StringType $name)
    {
    }

    public function resolve(DictionaryType|DocumentGraphContext $catalog): ArrayType
    {
        return $catalog instanceof DocumentGraphContext
            ? (new NavigationGraphValidation())->named($catalog, $this->name)
            : (new DestinationLookup())->find($this->name, $catalog);
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $this->resolve($catalog);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require($this->name instanceof NameType ? PdfVersion::PDF11 : PdfVersion::PDF12, 'Local named destination');
    }

    public function toObject(): NameType|StringType
    {
        return $this->name;
    }
}
