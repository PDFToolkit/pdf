<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** The sfnt sum of zero-padded, big-endian 32-bit words, modulo 2^32. */
final readonly class SfntChecksum
{
    public int $value;

    public function __construct(string $bytes)
    {
        $reader = new BinaryReader($bytes . str_repeat("\x00", (4 - strlen($bytes) % 4) % 4));
        $sum = 0;
        for ($offset = 0; $offset < $reader->length(); $offset += 4) {
            $sum = ($sum + $reader->uint32($offset)) & 0xFFFFFFFF;
        }
        $this->value = $sum;
    }
}
