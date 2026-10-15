<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StreamObject;

/**
 * Explicit external-format capability. Parse and rebuild complete font programs
 * without recoverable ancillary data; decode images to exact normalized pixel rows.
 * Returning an unchanged opaque program without validation violates this contract.
 */
interface RedactionResourceSanitizer
{
    public function font(DictionaryType $font): DictionaryType;
    public function image(StreamObject $image): StreamObject;
}
