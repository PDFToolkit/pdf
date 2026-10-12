<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\StringObject;

use PDF\Syntax\Object\StringType;
use PDF\Serialization\SerializationContext;

class LiteralString extends StringType
{
    public function toPdf(SerializationContext $context): string
    {
        $encoded = '';
        for ($i = 0, $length = strlen($this->value); $i < $length; ++$i) {
            $character = $this->value[$i];
            $byte = ord($character);
            $encoded .= match ($character) {
                '(' => '\\(', ')' => '\\)', '\\' => '\\\\',
                "\n" => '\\n', "\r" => '\\r', "\t" => '\\t', "\x08" => '\\b', "\x0c" => '\\f',
                default => $byte < 32 || $byte > 126 ? sprintf('\\%03o', $byte) : $character,
            };
        }
        return '(' . $encoded . ')';
    }
}
