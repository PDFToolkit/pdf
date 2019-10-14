<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\StreamObject;

/**
 * https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1913072
 */
class ContentStreams
{
    /** @var StreamObject */
    private StreamObject $streamObject;

    public function __construct(StreamObject $streamObject)
    {
        $this->streamObject = $streamObject;
    }

    public function __toString(): string
    {
        $objectIdentifier = ObjectIdentifier::instance();
        return sprintf(
            <<<PDF
%s %s obj
%s
endobj
PDF
            ,
            $objectIdentifier->objectNumber($this),
            $objectIdentifier->generationNumber(),
            $this->streamObject
        );
    }
}
