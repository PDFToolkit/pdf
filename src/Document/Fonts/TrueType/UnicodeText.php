<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** Unicode scalar values, without normalization, shaping or direction changes. */
final readonly class UnicodeText
{
    /** @var list<int> */
    public array $codePoints;

    public function __construct(public string $utf8)
    {
        if (strlen($utf8) > 1048576) {
            throw new \InvalidArgumentException('A UnicodeText value is limited to 1 MiB of UTF-8.');
        }
        $encoded = @iconv('UTF-8', 'UTF-32BE', $utf8);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Text must contain valid UTF-8 Unicode scalar values.');
        }
        $reader = new BinaryReader($encoded);
        $points = [];
        for ($offset = 0; $offset < $reader->length(); $offset += 4) {
            $point = $reader->uint32($offset);
            if ($point > 0x10FFFF || ($point >= 0xD800 && $point <= 0xDFFF)) {
                throw new \InvalidArgumentException('Text contains an invalid Unicode scalar value.');
            }
            $points[] = $point;
        }
        $this->codePoints = $points;
    }
}
