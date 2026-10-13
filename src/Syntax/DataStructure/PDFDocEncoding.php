<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

/** Annex D.3, Table D.2 (PDF pp. 664–672): strict, reversible PDFDocEncoding. */
final readonly class PDFDocEncoding
{
    /** @var array<int, int> */
    private const SPECIAL = [
        0x18 => 0x02D8, 0x19 => 0x02C7, 0x1A => 0x02C6, 0x1B => 0x02D9,
        0x1C => 0x02DD, 0x1D => 0x02DB, 0x1E => 0x02DA, 0x1F => 0x02DC,
        0x80 => 0x2022, 0x81 => 0x2020, 0x82 => 0x2021, 0x83 => 0x2026,
        0x84 => 0x2014, 0x85 => 0x2013, 0x86 => 0x0192, 0x87 => 0x2044,
        0x88 => 0x2039, 0x89 => 0x203A, 0x8A => 0x2212, 0x8B => 0x2030,
        0x8C => 0x201E, 0x8D => 0x201C, 0x8E => 0x201D, 0x8F => 0x2018,
        0x90 => 0x2019, 0x91 => 0x201A, 0x92 => 0x2122, 0x93 => 0xFB01,
        0x94 => 0xFB02, 0x95 => 0x0141, 0x96 => 0x0152, 0x97 => 0x0160,
        0x98 => 0x0178, 0x99 => 0x017D, 0x9A => 0x0131, 0x9B => 0x0142,
        0x9C => 0x0153, 0x9D => 0x0161, 0x9E => 0x017E, 0xA0 => 0x20AC,
    ];

    public function encode(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('PDFDocEncoding encodes valid UTF-8 text.');
        }
        if (preg_match('/^[\x09\x0a\x0d\x20-\x7e]*$/D', $text) === 1) {
            return $text;
        }
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new \InvalidArgumentException('The UTF-8 text could not be split.');
        }
        $bytes = '';
        foreach ($characters as $character) {
            $encoded = iconv('UTF-8', 'UTF-32BE', $character);
            if ($encoded === false) {
                throw new \InvalidArgumentException('The Unicode character could not be read.');
            }
            $scalar = ord($encoded[0]) * 16777216 + ord($encoded[1]) * 65536 + ord($encoded[2]) * 256 + ord($encoded[3]);
            $byte = array_search($scalar, self::SPECIAL, true);
            if ($byte === false && $scalar <= 255 && $this->defined($scalar) && !isset(self::SPECIAL[$scalar])) {
                $byte = $scalar;
            }
            if ($byte === false) {
                throw new \InvalidArgumentException('The character has no defined PDFDocEncoding code.');
            }
            $bytes .= chr($byte);
        }
        return $bytes;
    }

    public function decode(string $bytes): string
    {
        if (preg_match('/^[\x09\x0a\x0d\x20-\x7e]*$/D', $bytes) === 1) {
            return $bytes;
        }
        $text = '';
        for ($i = 0, $length = strlen($bytes); $i < $length; ++$i) {
            $byte = ord($bytes[$i]);
            if (!$this->defined($byte)) {
                throw new \InvalidArgumentException('Undefined PDFDocEncoding byte at offset ' . $i . '.');
            }
            $scalar = self::SPECIAL[$byte] ?? $byte;
            $character = iconv('UTF-32BE', 'UTF-8', pack('N', $scalar));
            if ($character === false) {
                throw new \LogicException('The defined PDFDocEncoding scalar could not be encoded.');
            }
            $text .= $character;
        }
        return $text;
    }

    public function defined(int $byte): bool
    {
        return in_array($byte, [9, 10, 13], true) || ($byte >= 24 && $byte <= 255 && !in_array($byte, [127, 159, 173], true));
    }
}
