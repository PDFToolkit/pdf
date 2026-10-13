<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Annex D immutable byte-to-glyph vector; missing codes denote .notdef. */
final readonly class EncodingVector
{
    /** @var array<int, NameType> */
    public array $glyphs;

    public function __construct(public EncodingVectorKind $kind)
    {
        $glyphs = [];
        $mapping = $kind === EncodingVectorKind::MacOSRoman ? array_replace(EncodingVectors::MAPS['MacRoman'], [
            173 => 'notequal', 176 => 'infinity', 178 => 'lessequal', 179 => 'greaterequal',
            182 => 'partialdiff', 183 => 'summation', 184 => 'product', 185 => 'pi',
            186 => 'integral', 189 => 'Omega', 195 => 'radical', 197 => 'approxequal',
            198 => 'Delta', 215 => 'lozenge', 219 => 'Euro', 240 => 'apple',
        ]) : EncodingVectors::MAPS[$kind->value];
        foreach ($mapping as $code => $glyph) {
            $glyphs[$code] = new NameType($glyph);
        }
        $this->glyphs = $glyphs;
    }

    public function glyph(IntegerType $code): NameType
    {
        if ($code->integer() < 0 || $code->integer() > 255) {
            throw new \InvalidArgumentException('Simple-font character codes are bytes.');
        }
        return $this->glyphs[$code->integer()] ?? new NameType('.notdef');
    }

    /** @return list<IntegerType> */
    public function codes(NameType $glyph): array
    {
        $codes = [];
        foreach ($this->glyphs as $code => $name) {
            if ($name->value === $glyph->value) {
                $codes[] = new IntegerType($code);
            }
        }
        return $codes;
    }
}
