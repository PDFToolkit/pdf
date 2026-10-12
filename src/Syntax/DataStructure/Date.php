<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\StringObject\LiteralString;

/** Fully specified PDF date, including its original UTC offset (7.9.4). */
final class Date extends LiteralString
{
    public function __construct(public readonly \DateTimeImmutable $dateTime)
    {
        $offset = $dateTime->format('P');
        $zone = $offset === '+00:00' ? 'Z' : substr($offset, 0, 3) . "'" . substr($offset, 4, 2) . "'";
        parent::__construct('D:' . $dateTime->format('YmdHis') . $zone);
    }
}
