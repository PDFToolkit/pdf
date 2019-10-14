<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention\CharacterSet;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1938426
 */
class WhiteSpace extends Character
{
    private const HORIZONTAL_TAB = 9;
    private const LINE_FEED = 10;
    private const FORM_FEED = 12;
    private const CARRIAGE_RETURN = 13;
    private const SPACE = 32;

    public static function HORIZONTAL_TAB(): string
    {
        return self::generate(self::HORIZONTAL_TAB);
    }

    public static function HT(): string
    {
        return self::generate(self::HORIZONTAL_TAB);
    }

    public static function LINE_FEED(): string
    {
        return self::generate(self::LINE_FEED);
    }

    public static function LF(): string
    {
        return self::generate(self::LINE_FEED);
    }

    public static function FORM_FEED(): string
    {
        return self::generate(self::FORM_FEED);
    }

    public static function FF(): string
    {
        return self::generate(self::FORM_FEED);
    }

    public static function CARRIAGE_RETURN(): string
    {
        return self::generate(self::CARRIAGE_RETURN);
    }

    public static function CR(): string
    {
        return self::generate(self::CARRIAGE_RETURN);
    }

    public static function SPACE(): string
    {
        return self::generate(self::SPACE);
    }

    public static function SP(): string
    {
        return self::generate(self::SPACE);
    }

    public static function EOF(): string
    {
        return sprintf('%s%s', self::CARRIAGE_RETURN(), self::LINE_FEED());
    }
}
