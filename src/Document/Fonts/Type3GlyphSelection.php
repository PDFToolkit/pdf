<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

/** ISO_32000-2_sponsored_EC3.pdf, 9.6.5.3, PDF p.340: actual single-byte CharProc selection. */
final readonly class Type3GlyphSelection
{
    /** @var list<Type3Glyph> */
    public array $glyphs;
    /** @var list<IntegerType> */
    public array $undefinedCodes;

    public function __construct(Type3Font $font, StringType $text, int $maximumBytes = 1048576)
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('Type 3 glyph selection requires a positive byte budget.');
        }
        if (strlen($text->value) > $maximumBytes) {
            throw new \LengthException('Type 3 glyph selection exceeds the supplied text byte budget.');
        }
        $glyphs = [];
        $undefined = [];
        $codes = [];
        $seen = new \SplObjectStorage();
        for ($index = 0, $length = strlen($text->value); $index < $length; ++$index) {
            $value = ord($text->value[$index]);
            if (isset($codes[$value])) {
                continue;
            }
            $codes[$value] = true;
            $code = new IntegerType($value);
            $name = $font->encoding->differences->glyph($code);
            $glyph = $name === null ? null : ($font->charProcs->glyphs[$name->value] ?? null);
            if ($name === null || $name->value === '.notdef' || $glyph === null) {
                $undefined[] = $code;
            } elseif (!$seen->offsetExists($glyph)) {
                $seen->offsetSet($glyph);
                $glyphs[] = $glyph;
            }
        }
        $this->glyphs = $glyphs;
        $this->undefinedCodes = $undefined;
    }
}
