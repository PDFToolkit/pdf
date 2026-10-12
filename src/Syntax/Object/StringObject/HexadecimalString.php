<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\StringObject;

use PDF\Syntax\Object\StringType;
use PDF\Serialization\SerializationContext;

final class HexadecimalString extends StringType
{
    public function toPdf(SerializationContext $context): string
    {
        return '<' . strtoupper(bin2hex($this->value)) . '>';
    }

    public static function fromHex(string $hex): self
    {
        $hex = preg_replace('/[\x00\x09\x0a\x0c\x0d\x20]/', '', $hex);
        if (!ctype_xdigit($hex) && $hex !== '') {
            throw new \InvalidArgumentException('Invalid hexadecimal data.');
        }
        if (strlen($hex) % 2 !== 0) {
            $hex .= '0';
        }
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Unable to decode hexadecimal data.');
        }
        return new self($bytes);
    }
}
