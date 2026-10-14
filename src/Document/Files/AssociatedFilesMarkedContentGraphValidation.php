<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Named MCAF uses in native content and effective resources (14.13.5, PDF pp.854–855/1015). */
final readonly class AssociatedFilesMarkedContentGraphValidation
{
    public function validate(ContentStreams $content, DictionaryType $resources, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $this->validateFacts(new AssociatedFilesMarkedContentFacts($content), $resources, $graph, $version);
    }

    public function validateFacts(AssociatedFilesMarkedContentFacts $facts, DictionaryType $resources, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        foreach ($facts->uses as $use) {
            $value = $use->properties;
            if ($value instanceof DictionaryType) {
                $inline = $graph->forDirectValue($value);
                if ($inline->entry($value, 'MCAF') !== null) {
                    if ($use->symbol !== 'BDC') {
                        throw new \InvalidArgumentException('Associated-file marked content requires BDC and EMC, not a marked-content point.');
                    }
                    throw new \InvalidArgumentException('Associated-file marked content requires a named Properties resource, never an inline property list.');
                }
                // 14.13.5 allows other meanings of the AF tag; those are not file associations.
                continue;
            }
            if ($use->declared) {
                $version->require(PdfVersion::PDF20, 'Associated-file marked content');
            }
            $properties = $graph->entry($resources, 'Properties');
            $propertyGraph = $properties instanceof IndirectReference || $properties === null ? $graph : $graph->forDirectValue($properties);
            $dictionary = $properties === null ? null : $propertyGraph->dictionary($properties);
            $resource = $dictionary === null ? null : $propertyGraph->entry($dictionary, $value);
            $listGraph = $resource instanceof IndirectReference || $resource === null ? $graph : $graph->forDirectValue($resource);
            $list = $resource === null ? null : $listGraph->dictionary($resource);
            if ($list === null) {
                throw new \InvalidArgumentException('The AF marked-content property resource is missing or does not resolve to a dictionary: /' . $value->value . '.');
            }
            $files = $listGraph->entry($list, 'MCAF');
            if ($files === null) {
                if ($use->declared) {
                    throw new \InvalidArgumentException('The associated-file marked-content property resource requires MCAF.');
                }
                continue;
            }
            if ($use->symbol !== 'BDC') {
                throw new \InvalidArgumentException('Associated-file marked content requires BDC and EMC, not a marked-content point.');
            }
            $files = $listGraph->resolve($files);
            if (!$files instanceof ArrayType) {
                throw new \InvalidArgumentException('MCAF requires an array of associated file specifications.');
            }
            (new AssociatedFilesGraphValidation())->validateFiles($files, $listGraph, $version, 'MCAF');
        }
    }
}
