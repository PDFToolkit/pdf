<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Explicit Properties resource binding and /OC BDC instruction (8.11.3.2). */
final readonly class OptionalContentAssociation
{
    public function __construct(public NameType $resourceName, public OptionalContentObject $membership)
    {
    }

    public function propertyEntry(): Entry
    {
        return new Entry($this->resourceName, new IndirectReference($this->membership));
    }

    public function begin(): BeginMarkedContentWithProperties
    {
        return new BeginMarkedContentWithProperties(new NameType('OC'), $this->resourceName);
    }
}
