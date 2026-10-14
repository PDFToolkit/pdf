<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** An absolute RFC 3986 URI identifying a vocabulary/profile; never dereferenced. */
final readonly class MetadataUri
{
    public function __construct(public string $value)
    {
        new XmlText($value);
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:[^\x00-\x20\x7F-\xFF<>"{}|\\\\^`]*$/D', $value) !== 1 || preg_match('/%(?![0-9A-Fa-f]{2})/', $value) !== 0 || substr_count($value, '#') > 1) {
            throw new \InvalidArgumentException('A metadata identifier requires an absolute URI with valid percent escapes.');
        }
    }
}
