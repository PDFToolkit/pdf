<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** EC3 #296, physical p.730: an encoding check, not an XML/XMP schema validator. */
final readonly class XmpUtf8Encoding
{
    public function accepts(string $bytes): bool
    {
        if (preg_match('//u', $bytes) !== 1 || str_contains($bytes, "\0")) {
            return false;
        }
        // A declaration of another encoding must not reinterpret otherwise valid ASCII bytes.
        if (preg_match('/\A(?:\xEF\xBB\xBF)?<\?xml\s+[^?]*\bencoding\s*=\s*([\'"])([^\'"]+)\1/i', $bytes, $match) === 1 && strcasecmp($match[2], 'UTF-8') !== 0) {
            return false;
        }
        return true;
    }

    public function validate(string $bytes): void
    {
        if (!$this->accepts($bytes)) {
            throw new \InvalidArgumentException('PDF2 XMP metadata requires UTF-8 bytes and a compatible XML encoding declaration (EC3 #296).');
        }
    }
}
