<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;

final readonly class RunLengthDecode implements Filter
{
    public function name(): NameType
    {
        return new NameType('RunLengthDecode');
    }
    public function parameters(): ?DictionaryType
    {
        return null;
    }
    public function encode(string $bytes): string
    {
        $encoded = '';
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length;) {
            $run = 1;
            while ($run < 128 && $offset + $run < $length && $bytes[$offset + $run] === $bytes[$offset]) {
                ++$run;
            }
            if ($run >= 3) {
                $encoded .= chr(257 - $run) . $bytes[$offset];
                $offset += $run;
                continue;
            }
            $start = $offset;
            $offset += min($run, 128);
            while ($offset < $length && $offset - $start < 128) {
                if ($offset + 2 < $length && $bytes[$offset] === $bytes[$offset + 1] && $bytes[$offset] === $bytes[$offset + 2]) {
                    break;
                }
                ++$offset;
            }
            $literal = substr($bytes, $start, $offset - $start);
            $literalLength = strlen($literal);
            if ($literalLength < 1 || $literalLength > 128) {
                throw new \LogicException('Invalid run-length literal packet size.');
            }
            $encoded .= chr($literalLength - 1) . $literal;
        }
        return $encoded . chr(128);
    }
}
