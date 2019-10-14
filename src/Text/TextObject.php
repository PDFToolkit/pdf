<?php

declare(strict_types=1);

namespace PDF\Text;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/**
 * https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1840319
 */
class TextObject
{
    /** @var string */
    private string $text;

    /** @var Font */
    private Font $font;

    public function __construct(string $text, Font $font)
    {
        $this->text = $text;
        $this->font = $font;
    }

    public function __toString(): string
    {

        return sprintf(<<<PDF
%s
%s %s
(%s) %s
%s
PDF
            ,
            Operator::BEGIN_TEXT_OBJECT,
            $this->font,
            Operator::SET_TEXT_FONT_AND_SIZE,
            $this->text,
            Operator::SHOW_TEXT,
            Operator::END_TEXT_OBJECT
        );
    }
}
