<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Table 114 constraints also apply to explicitly supplied low-level encoding dictionaries. */
final readonly class FontEncodingValidation
{
    public function validate(NameType|DictionaryType $encoding): void
    {
        if ($encoding instanceof NameType) {
            if (PredefinedEncoding::tryFrom($encoding->value) === null) {
                throw new \InvalidArgumentException('A named simple font Encoding is MacRoman, WinAnsi or MacExpert.');
            }
            return;
        }
        $type = $encoding->get('Type');
        if ($type !== null && (!$type instanceof NameType || $type->value !== 'Encoding')) {
            throw new \InvalidArgumentException('An encoding dictionary Type, when present, is Encoding.');
        }
        $base = $encoding->get('BaseEncoding');
        if ($base !== null && (!$base instanceof NameType || PredefinedEncoding::tryFrom($base->value) === null)) {
            throw new \InvalidArgumentException('BaseEncoding is one of the three predefined simple-font encodings.');
        }
        $differences = $encoding->get('Differences');
        $differences = $differences instanceof IndirectReference ? $differences->target() : $differences;
        if ($differences === null) {
            return;
        }
        if (!$differences instanceof ArrayType) {
            throw new \InvalidArgumentException('Differences is an array of byte codes and glyph names.');
        }
        $code = null;
        $needsName = false;
        $seen = [];
        foreach ($differences->items as $value) {
            if ($value instanceof IntegerType) {
                if ($needsName) {
                    throw new \InvalidArgumentException('Each Differences starting code is followed by glyph names.');
                }
                if ($value->integer() < 0 || $value->integer() > 255) {
                    throw new \InvalidArgumentException('A Differences starting code is a byte.');
                }
                $code = $value->integer();
                $needsName = true;
            } elseif ($value instanceof NameType) {
                if ($code === null || $code > 255 || $value->value === '' || isset($seen[$code])) {
                    throw new \InvalidArgumentException('Differences names follow a byte code, with no overlap or overflow.');
                }
                $seen[$code] = true;
                $needsName = false;
                ++$code;
            } else {
                throw new \InvalidArgumentException('Differences contains only integer codes and glyph names.');
            }
        }
        if ($needsName) {
            throw new \InvalidArgumentException('Each Differences starting code is followed by glyph names.');
        }
    }
}
