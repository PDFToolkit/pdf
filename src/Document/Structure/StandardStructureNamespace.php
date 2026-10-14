<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** ISO_32000-2_sponsored_EC3.pdf, 14.8.6, physical PDF pages 809–810. */
enum StandardStructureNamespace: string
{
    case PDF17 = 'http://iso.org/pdf/ssn';
    case PDF20 = 'http://iso.org/pdf2/ssn';
    case MathML = 'http://www.w3.org/1998/Math/MathML';
}
