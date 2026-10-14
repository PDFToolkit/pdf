<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** PDF20_AN003-ObjectMetadataLocations.pdf pp.4–10: effective ownership and indirect stream type. */
final readonly class MetadataGraphValidation
{
    public function validate(DictionaryType $owner, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $metadata = $graph->entry($owner, 'Metadata');
        if ($metadata !== null) {
            $version->require(PdfVersion::PDF14, 'XML metadata');
            if (!$metadata instanceof IndirectReference || !$graph->resolve($metadata) instanceof StreamObject) {
                throw new \InvalidArgumentException('Metadata must be an indirect reference to an XML metadata stream.');
            }
            $dictionary = $graph->dictionary($metadata) ?? throw new \InvalidArgumentException('Missing effective metadata stream.');
            foreach (['Type' => 'Metadata', 'Subtype' => 'XML'] as $key => $expected) {
                $value = $graph->entry($dictionary, $key);
                $value = $value === null ? null : $graph->resolve($value);
                if (!$value instanceof NameType || $value->value !== $expected) {
                    throw new \InvalidArgumentException('Metadata requires a stream with Type Metadata and Subtype XML.');
                }
            }
        }
    }
}
