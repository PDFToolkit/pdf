<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;

/** 7.3.5. The value is the unescaped byte sequence, without the leading slash. */
final readonly class NameType implements BasicType
{
    use SerializesPdf;
    public function __construct(public string $value)
    {
        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException('A PDF name cannot contain a null byte.');
        }
    }

    public function toPdf(SerializationContext $context): string
    {
        $encoded = '';
        for ($i = 0, $length = strlen($this->value); $i < $length; ++$i) {
            $byte = ord($this->value[$i]);
            $encoded .= $byte < 33 || $byte > 126 || str_contains('()<>[]{}/%#', $this->value[$i])
                ? sprintf('#%02X', $byte) : $this->value[$i];
        }
        return '/' . $encoded;
    }
}
