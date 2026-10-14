<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** ISO 32000-2 Table347, PDF p.730; UTF-8 XML is independent of PDF string encodings. */
final class MetadataStream extends StreamObject implements VersionedComponent
{
    public function __construct(public readonly XmpPacket $packet, Filter ...$filters)
    {
        parent::__construct($packet->xml, new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))), ...$filters);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF14, 'XML metadata streams');
    }
}
