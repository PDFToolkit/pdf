<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;

/** Actual consumed bytes and selected codespace width, including an undefined/truncated code. */
final readonly class DecodedCMapCode
{
    public function __construct(public CMapCode $code, public IntegerType $expectedLength, public bool $defined)
    {
        if ($expectedLength->integer() < 1 || $expectedLength->integer() > 4 || $code->length() > $expectedLength->integer() || ($defined && $code->length() !== $expectedLength->integer())) {
            throw new \InvalidArgumentException('Decoded code lengths must agree with their selected 1–4-byte codespace.');
        }
    }
}
