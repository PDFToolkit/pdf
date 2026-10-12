<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\StreamObject;

use PDF\Syntax\Object\IntegerType;

/**
 * @see https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1919337
 */
final class StreamExtent extends IntegerType
{
    public function __construct(string $encodedBytes)
    {
        parent::__construct(strlen($encodedBytes));
    }
}
