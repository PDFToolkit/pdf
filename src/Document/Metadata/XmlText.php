<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** An XML 1.0 character sequence; encoding is UTF-8, never PDF text-string encoding. */
final readonly class XmlText
{
    public function __construct(public string $value)
    {
        if (preg_match('//u', $value) !== 1 || preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value) !== 0) {
            throw new \InvalidArgumentException('XMP text requires well-formed UTF-8 and XML 1.0 characters.');
        }
    }

    public function escaped(): string
    {
        return htmlspecialchars($this->value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
