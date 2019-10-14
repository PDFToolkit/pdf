<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\LexicalConvention\CharacterSet\WhiteSpace;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1638996
 */
class IndirectReference
{
    /** @var object */
    private $objectBasic;

    /** @var bool */
    private $isParent;

    public function __construct(object $objectBasic)
    {
        $this->objectBasic = $objectBasic;
    }

    public function __toString(): string
    {
        $objectIdentifier = ObjectIdentifier::instance();

        return sprintf('%s %s R%s', $objectIdentifier->objectNumber($this->objectBasic), $objectIdentifier->generationNumber(), WhiteSpace::EOF());
    }
}
