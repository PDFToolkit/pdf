<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention\CharacterSet;

/** An immutable character byte, independent of text encoding (7.2.2). */
readonly class Character implements \Stringable
{
    /** @var int<0, 255> */
    public int $byteValue;

    public function __construct(int $byteValue)
    {
        if ($byteValue < 0 || $byteValue > 255) {
            throw new \InvalidArgumentException('A character byte must be in 0..255.');
        }
        $this->byteValue = $byteValue;
    }

    public function __toString(): string
    {
        return chr($this->byteValue);
    }
}
