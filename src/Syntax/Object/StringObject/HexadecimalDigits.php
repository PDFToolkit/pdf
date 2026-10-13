<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\StringObject;

/** PDF hexadecimal string digits; PDF32000_2008.pdf, 7.3.4.3, PDF page 24. */
final readonly class HexadecimalDigits
{
    private string $decoded;

    public function __construct(string $digits)
    {
        $digits = preg_replace('/[\x00\x09\x0a\x0c\x0d\x20]/', '', $digits);
        if ($digits === null || ($digits !== '' && !ctype_xdigit($digits))) {
            throw new \InvalidArgumentException('Invalid PDF hexadecimal digits.');
        }
        if (strlen($digits) % 2 !== 0) {
            $digits .= '0';
        }
        $bytes = hex2bin($digits);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Unable to decode hexadecimal digits.');
        }
        $this->decoded = $bytes;
    }

    public function bytes(): string
    {
        return $this->decoded;
    }
}
