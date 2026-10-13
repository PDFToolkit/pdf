<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

/** ISO 12.7.8, pp.472–473: alternating packet names and XML stream references. */
final class XfaPackets extends ArrayType
{
    public function __construct(XfaPacket ...$packets)
    {
        $items = [];
        foreach ($packets as $packet) {
            $items[] = $packet->name;
            $items[] = new IndirectReference($packet->xml);
        }
        parent::__construct(...$items);
    }
}
