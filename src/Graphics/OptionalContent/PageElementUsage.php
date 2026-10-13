<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class PageElementUsage extends UsageEntry
{
    public function __construct(PageElementType $type)
    {
        parent::__construct(UsageCategory::PageElement, new Entry(new NameType('Subtype'), new NameType($type->value)));
    }
}
