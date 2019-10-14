<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\StringClass;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Text\TextObject;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1840319
 */
class StreamObject implements StringClass
{
    /** @var TextObject */
    private TextObject $text;

    public function __construct(TextObject $text)
    {
        $this->text = $text;
    }

    public function __toString(): string
    {

        return sprintf(
            <<<PDF
stream
%s
endstream
PDF
            ,
            $this->text
        );
    }
}
