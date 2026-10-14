<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

/** XML 1.0 Name production, on decoded Unicode characters rather than PDF name escapes. */
final readonly class XmlName
{
    public function __construct(public string $value)
    {
        $start = ':A-Z_a-z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';
        if (preg_match('/^[' . $start . '][' . $start . '.0-9\x{B7}\x{0300}-\x{036F}\x{203F}-\x{2040}-]*$/uD', $value) !== 1) {
            throw new \InvalidArgumentException('An XML Name requires a valid first character and valid Unicode name characters.');
        }
    }
}
