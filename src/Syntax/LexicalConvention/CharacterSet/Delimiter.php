<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention\CharacterSet;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1839343
 */
class Delimiter extends Character
{
    private const LEFT_PARENTHESIS = 40;
    private const RIGHT_PARENTHESIS = 41;
    private const LESS_THAN_SIGN = 60;
    private const GREATER_THAN_SIGN = 62;
    private const LEFT_SQUARE_BRACKET = 91;
    private const RIGHT_SQUARE_BRACKET = 93;
    private const LEFT_CURLY_BRACKET = 123;
    private const RIGHT_CURLY_BRACKET = 125;
    private const SOLIDUS = 47;
    private const PERCENT_SIGN = 37;

    public static function LEFT_PARENTHESIS(): string
    {
        return self::generate(self::LEFT_PARENTHESIS);
    }

    public static function RIGHT_PARENTHESIS(): string
    {
        return self::generate(self::RIGHT_PARENTHESIS);
    }

    public static function LESS_THAN_SIGN(): string
    {
        return self::generate(self::LESS_THAN_SIGN);
    }

    public static function GREATER_THAN_SIGN(): string
    {
        return self::generate(self::GREATER_THAN_SIGN);
    }

    public static function LEFT_SQUARE_BRACKET(): string
    {
        return self::generate(self::LEFT_SQUARE_BRACKET);
    }

    public static function RIGHT_SQUARE_BRACKET(): string
    {
        return self::generate(self::RIGHT_SQUARE_BRACKET);
    }

    public static function LEFT_CURLY_BRACKET(): string
    {
        return self::generate(self::LEFT_CURLY_BRACKET);
    }

    public static function RIGHT_CURLY_BRACKET(): string
    {
        return self::generate(self::RIGHT_CURLY_BRACKET);
    }

    public static function SOLIDUS(): string
    {
        return self::generate(self::SOLIDUS);
    }

    public static function PERCENT_SIGN(): string
    {
        return self::generate(self::PERCENT_SIGN);
    }
}
