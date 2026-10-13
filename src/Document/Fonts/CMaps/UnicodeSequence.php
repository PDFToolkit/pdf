<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\StringObject\HexadecimalString;

/** UTF-16BE destination string without an encoding BOM, max 512 bytes (ISO pp.301–303). */
readonly class UnicodeSequence
{
    public function __construct(public HexadecimalString $value)
    {
        $bytes = $value->value;
        if (strlen($bytes) % 2 !== 0 || strlen($bytes) > 512) {
            throw new \InvalidArgumentException('A ToUnicode destination is an even-length UTF-16BE sequence of at most 512 bytes.');
        }
        for ($offset = 0; $offset < strlen($bytes); $offset += 2) {
            $unit = ord($bytes[$offset]) * 256 + ord($bytes[$offset + 1]);
            if ($unit >= 0xd800 && $unit <= 0xdbff) {
                if ($offset + 3 >= strlen($bytes)) {
                    throw new \InvalidArgumentException('A high UTF-16 surrogate needs its matching low surrogate.');
                }
                $low = ord($bytes[$offset + 2]) * 256 + ord($bytes[$offset + 3]);
                if ($low < 0xdc00 || $low > 0xdfff) {
                    throw new \InvalidArgumentException('A high UTF-16 surrogate needs its matching low surrogate.');
                }
                $offset += 2;
            } elseif ($unit >= 0xdc00 && $unit <= 0xdfff) {
                throw new \InvalidArgumentException('A low UTF-16 surrogate cannot stand alone.');
            }
        }
    }

    public function hex(): string
    {
        return '<' . strtoupper(bin2hex($this->value->value)) . '>';
    }

    /** ISO p.303 increments only the last byte; no carry into preceding bytes is permitted. */
    public function increment(int $amount): self
    {
        $bytes = $this->value->value;
        if ($bytes === '' || $amount < 0 || $amount > 255 - ord($bytes[strlen($bytes) - 1])) {
            throw new \OverflowException('A bfrange increment cannot overflow the destination last byte.');
        }
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) + $amount);
        return new self(new HexadecimalString($bytes));
    }

    public function utf8(): string
    {
        $text = iconv('UTF-16BE', 'UTF-8', $this->value->value);
        return $text === false ? throw new \LogicException('Validated Unicode sequence conversion failed.') : $text;
    }
}
