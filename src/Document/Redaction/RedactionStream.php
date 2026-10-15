<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Document\ResourceDictionary;
use PDF\Syntax\ContentStreamsResources\ContentStreams;

final readonly class RedactionStream
{
    public function __construct(public ContentStreams $content, public ResourceDictionary $resources)
    {
    }
}
