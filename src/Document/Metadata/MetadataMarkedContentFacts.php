<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\MarkedContentPointWithProperties;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Metadata usages must survive incremental writes even when a caller's content builder changes. */
final readonly class MetadataMarkedContentFacts
{
    /** @var list<MetadataMarkedContentUse> */
    public array $uses;

    public function __construct(ContentStreams $content)
    {
        $uses = [];
        foreach ($content->operators as $operator) {
            if (!$operator instanceof BeginMarkedContentWithProperties && !$operator instanceof MarkedContentPointWithProperties) {
                continue;
            }
            $properties = $operator->operands[1] ?? null;
            if ($properties instanceof DictionaryType) {
                // PDF stream references are not permitted as operands encoded inline in content.
                $metadata = $properties->get('Metadata');
                if ($metadata !== null && !$metadata instanceof NullType) {
                    throw new \InvalidArgumentException('Metadata marked content requires a named Properties resource, never an inline property dictionary.');
                }
                continue;
            }
            if ($properties instanceof NameType) {
                $uses[] = new MetadataMarkedContentUse($operator->symbol(), $properties);
            }
        }
        $this->uses = $uses;
    }
}
