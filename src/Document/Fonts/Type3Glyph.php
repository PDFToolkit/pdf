<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\ContentStreamsResources\Type3GlyphContentStream;
use PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidth;
use PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidthAndBoundingBox;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** One CharProcs stream with validated d0/d1 scope and its declared metrics. */
final class Type3Glyph extends StreamObject
{
    public readonly NumericType $width;
    public readonly ?Rectangle $bounds;
    public function __construct(public readonly Type3GlyphContentStream $content)
    {
        $content->validate();
        $metrics = $content->operators[0] ?? null;
        if (!$metrics instanceof SetGlyphWidth && !$metrics instanceof SetGlyphWidthAndBoundingBox) {
            throw new \InvalidArgumentException('A Type 3 glyph begins with a typed d0 or d1 metric operator.');
        }
        $this->width = $metrics->wx;
        $this->bounds = $metrics instanceof SetGlyphWidthAndBoundingBox ? new Rectangle($metrics->llx, $metrics->lly, $metrics->urx, $metrics->ury) : null;
        parent::__construct($content);
    }
}
