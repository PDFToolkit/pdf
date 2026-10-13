<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** An immutable sfnt table and its directory checksum. */
final readonly class FontTable
{
    public SfntChecksum $checksum;

    public function __construct(public string $tag, public string $bytes)
    {
        if (!preg_match('/^[\x20-\x7E]{4}$/D', $tag)) {
            throw new \InvalidArgumentException('An sfnt table tag contains four printable ASCII bytes.');
        }
        if ($tag === 'head' && strlen($bytes) < 12) {
            throw new \InvalidArgumentException('The head table must contain its checksum adjustment field.');
        }
        // The head table checksum is computed with checksumAdjustment cleared.
        $this->checksum = new SfntChecksum($tag === 'head' ? substr_replace($bytes, "\x00\x00\x00\x00", 8, 4) : $bytes);
    }
}
