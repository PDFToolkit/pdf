<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Structure\WtPdfRichTextParser;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\FileStructure\PdfVersion;

/** Explicit per-update XML interpretation for protected rich-text filling (ISO2 12.7.4.3, PDF548). */
final readonly class RichTextFilling
{
    public function __construct(public WtPdfRichTextParser $parser, public int $maximumBytes = 1048576)
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('Rich-text filling requires a positive text-byte limit.');
        }
    }

    public function validate(StringType $value, StringType $richValue, PdfVersion $version = PdfVersion::PDF17): void
    {
        if (strlen($value->value) > $this->maximumBytes || strlen($richValue->value) > $this->maximumBytes) {
            throw new \LengthException('Protected rich-text values exceed their byte limit.');
        }
        $decoder = new TextStringDecoder($version);
        $plain = str_replace(["\r\n", "\r"], "\n", $decoder->decode($value));
        $extracted = $this->parser->plainText($decoder->decode($richValue));
        if ($plain !== $extracted) {
            throw new \LogicException('Protected rich-text RV and plain V must represent the same text.');
        }
    }
}
