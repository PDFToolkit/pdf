<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

/** ISO12.4.2/Table159, PDF p383. Alphabetic labels repeat the same letter. */
enum LabelNumbering: string
{
    case Decimal = 'D';
    case UpperRoman = 'R';
    case LowerRoman = 'r';
    case UpperLetters = 'A';
    case LowerLetters = 'a';

    public function format(int $number, NavigationLimits $limits = new NavigationLimits()): string
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Page label numbers start at one.');
        }
        if ($this === self::Decimal) {
            $text = (string) $number;
            if (strlen($text) > $limits->maxLabelBytes) {
                throw new \LengthException('The decimal label exceeds its explicit output budget.');
            }
            return $text;
        }
        if ($this === self::UpperLetters || $this === self::LowerLetters) {
            $count = intdiv($number - 1, 26) + 1;
            if ($count > $limits->maxLabelBytes) {
                throw new \LengthException('The repeated alphabetic label exceeds its explicit output budget.');
            }
            return str_repeat(chr(($this === self::UpperLetters ? 65 : 97) + ($number - 1) % 26), $count);
        }
        $text = '';
        foreach ([1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $value => $letters) {
            $count = intdiv($number, $value);
            if ($count > intdiv($limits->maxLabelBytes - strlen($text), strlen($letters))) {
                throw new \LengthException('The Roman label exceeds its explicit output budget.');
            }
            $text .= str_repeat($letters, $count);
            $number %= $value;
        }
        return $this === self::LowerRoman ? strtolower($text) : $text;
    }
}
