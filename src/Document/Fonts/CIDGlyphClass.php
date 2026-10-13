<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** Table 125 names; the class inventory depends on the Adobe character collection. */
enum CIDGlyphClass: string
{
    case Alphabetic = 'Alphabetic';
    case AlphaNum = 'AlphaNum';
    case Dingbats = 'Dingbats';
    case DingbatsRot = 'DingbatsRot';
    case Generic = 'Generic';
    case GenericRot = 'GenericRot';
    case Hanzi = 'Hanzi';
    case Kanji = 'Kanji';
    case HojoKanji = 'HojoKanji';
    case Hangul = 'Hangul';
    case Hanja = 'Hanja';
    case HKana = 'HKana';
    case HKanaRot = 'HKanaRot';
    case HRoman = 'HRoman';
    case HRomanRot = 'HRomanRot';
    case Kana = 'Kana';
    case Proportional = 'Proportional';
    case ProportionalRot = 'ProportionalRot';
    case Ruby = 'Ruby';

    public function supports(string $ordering): bool
    {
        return match ($ordering) {
            'GB1', 'CNS1' => in_array($this, [self::Alphabetic, self::Dingbats, self::Generic, self::Hanzi, self::HRoman, self::HRomanRot, self::Kana, self::Proportional, self::ProportionalRot], true),
            'Japan1' => in_array($this, [self::Alphabetic, self::AlphaNum, self::Dingbats, self::DingbatsRot, self::Generic, self::GenericRot, self::HKana, self::HKanaRot, self::HRoman, self::HRomanRot, self::Kana, self::Kanji, self::Proportional, self::ProportionalRot, self::Ruby], true),
            'Japan2' => in_array($this, [self::Alphabetic, self::Dingbats, self::HojoKanji], true),
            'Korea1' => in_array($this, [self::Alphabetic, self::Dingbats, self::Generic, self::Hangul, self::Hanja, self::HRoman, self::HRomanRot, self::Kana, self::Proportional, self::ProportionalRot], true),
            default => false,
        };
    }
}
