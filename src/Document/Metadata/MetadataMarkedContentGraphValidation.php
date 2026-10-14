<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** PDF20_AN003-ObjectMetadataLocations.pdf p.8: resolve metadata from the owning content resource scope. */
final readonly class MetadataMarkedContentGraphValidation
{
    public function validate(ContentStreams $content, DictionaryType $resources, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $this->validateFacts(new MetadataMarkedContentFacts($content), $resources, $graph, $version);
    }

    public function validateFacts(MetadataMarkedContentFacts $facts, DictionaryType $resources, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        foreach ($facts->uses as $use) {
            $properties = $graph->entry($resources, 'Properties');
            $projection = $properties instanceof IndirectReference || $properties === null ? $graph : $graph->forDirectValue($properties);
            $dictionary = $properties === null ? null : $projection->dictionary($properties);
            $resource = $dictionary === null ? null : $projection->entry($dictionary, $use->properties);
            if ($resource === null) {
                throw new \InvalidArgumentException('A named marked-content property requires an entry in the current Properties resource dictionary.');
            }
            $projection = $resource instanceof IndirectReference ? $projection : $projection->forDirectValue($resource);
            $dictionary = $projection->dictionary($resource);
            if ($dictionary === null) {
                throw new \InvalidArgumentException('A named marked-content property must resolve to a dictionary.');
            }
            (new MetadataGraphValidation())->validate($dictionary, $projection, $version);
        }
    }
}
