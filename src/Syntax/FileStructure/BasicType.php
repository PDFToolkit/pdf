<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\SerializationContext;

/** ISO 32000-1:2008, 7.3: the PDF object domain. */
interface BasicType extends \Stringable
{
    public function toPdf(SerializationContext $context): string;
}
