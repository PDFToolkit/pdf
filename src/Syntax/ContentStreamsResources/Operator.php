<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources;

/**
 * https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1840319
 */
class Operator
{
    public const BEGIN_TEXT_OBJECT = 'BT';
    public const BEGIN_COMPATIBILITY_SECTION = 'BX';
    public const END_TEXT_OBJECT = 'ET';
    public const END_COMPATIBILITY_SECTION = 'EX';
    public const MOVE_TEXT_POSITION = 'Td';
    public const MOVE_TEXT_POSITION_SET_LEADING = 'TD';
    public const SET_TEXT_FONT_AND_SIZE = 'Tf';
    public const SHOW_TEXT = 'Tj';
    public const SHOW_TEXT_ALLOWING_INDIVIDUAL_GLYPH_POSITIONING = 'TJ';
}
