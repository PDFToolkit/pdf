<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention\CharacterSet;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1839343
 */
abstract class Character
{
    public static function generate(int $bytevalue)
    {
        return sprintf('%c', $bytevalue);
    }
}
