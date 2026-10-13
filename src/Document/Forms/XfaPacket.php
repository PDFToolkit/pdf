<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\StreamObject;

/** An already prepared XML packet. XDP/XFA interpretation belongs to its external specification. */
final readonly class XfaPacket
{
    public function __construct(public TextString $name, public StreamObject $xml)
    {
    }
}
