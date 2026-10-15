<?php

declare(strict_types=1);

namespace PDF\Document\Annotations;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;

/** One actual annotation occurrence in an effective page's Annots array. */
final readonly class PageAnnotation
{
    public function __construct(public BasicType $reference, public DictionaryType $dictionary, public DocumentGraphContext $graph, public BasicType $page, public string $identity, public string $pageIdentity)
    {
    }
}
