<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;

final readonly class ASCII85Decode implements Filter
{
    public function name(): NameType
    {
        return new NameType('ASCII85Decode');
    }
    public function parameters(): ?DictionaryType
    {
        return null;
    }
    public function encode(string $bytes): string
    {
        $encoded = '';
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += 4) {
            $group = substr($bytes, $offset, 4);
            $count = strlen($group);
            $padded = str_pad($group, 4, "\0");
            $number = ord($padded[0]) * 16777216 + ord($padded[1]) * 65536 + ord($padded[2]) * 256 + ord($padded[3]);
            if ($number === 0 && $count === 4) {
                $encoded .= 'z';
                continue;
            }
            $digits = '';
            for ($i = 0; $i < 5; ++$i) {
                $digit = $number % 85;
                if ($digit < 0) {
                    throw new \LogicException('ASCII85 requires an unsigned input word.');
                }
                $digits = chr($digit + 33) . $digits;
                $number = intdiv($number, 85);
            }
            $encoded .= substr($digits, 0, $count + 1);
        }
        return $encoded . '~>';
    }
}
