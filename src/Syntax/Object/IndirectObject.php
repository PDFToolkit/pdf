<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\LexicalConvention\CharacterSet\Delimiter;
use PDF\Syntax\LexicalConvention\CharacterSet\WhiteSpace;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1638996
 */
class IndirectObject
{
    /** @var BasicType */
    private $objectBasic;

    public function __construct(BasicType $objectBasic)
    {
        $this->objectBasic = $objectBasic;
    }

    public function __toString(): string
    {
        $objectIdentifier = ObjectIdentifier::instance();

        $string = '';

        $string .= sprintf(
            '%s %s obj%s%s',
            $objectIdentifier->objectNumber($this->objectBasic),
            $objectIdentifier->generationNumber(),
            WhiteSpace::EOF(),
            $this->objectBasic
        );

        return $string;
    }

    /**
     * A dictionary shall be written as a sequence of key-value pairs enclosed in double angle brackets (<< … >>)
     */
    private function enclose(BasicType $objectBasic)
    {
        return sprintf(
            '%s%s %s %s%s%sendobj%s',
            Delimiter::LESS_THAN_SIGN(),
            Delimiter::LESS_THAN_SIGN(),
            $objectBasic,
            Delimiter::GREATER_THAN_SIGN(),
            Delimiter::GREATER_THAN_SIGN(),
            WhiteSpace::EOF(),
            WhiteSpace::EOF()
        );
    }
}
