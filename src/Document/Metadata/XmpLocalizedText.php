<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** One RFC 3066/BCP 47 language choice in an XMP alternate-text property. */
final readonly class XmpLocalizedText
{
    public function __construct(public string $language, public XmlText $text)
    {
        if ($language !== 'x-default' && preg_match('/^[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*$/D', $language) !== 1) {
            throw new \InvalidArgumentException('An XMP language choice requires a language tag or x-default.');
        }
    }
}
