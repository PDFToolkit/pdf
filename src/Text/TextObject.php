<?php

declare(strict_types=1);

namespace PDF\Text;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\Object\StringType;

/** A composed BT/Tf/Tj/ET text object; all operands remain PDF objects (9.4). */
final readonly class TextObject implements \Stringable
{
    public function __construct(public StringType $text, public Font $font)
    {
    }

    public function contentStream(): ContentStreams
    {
        return new ContentStreams(
            new BeginTextObject(),
            new SetTextFontAndSize($this->font->resourceName, $this->font->size),
            new ShowText($this->text),
            new EndTextObject(),
        );
    }

    public function toPdf(SerializationContext $context): string
    {
        return $this->contentStream()->toPdf($context);
    }

    public function __toString(): string
    {
        return $this->toPdf(new SerializationContext());
    }
}
