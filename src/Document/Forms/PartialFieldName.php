<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\DataStructure\TextString;

/** ISO 32000-1, 12.7.3.2, PDF p.442: periods delimit qualified names. */
final readonly class PartialFieldName
{
    public function __construct(public TextString $value)
    {
        if (str_contains($value->text, '.')) {
            throw new \InvalidArgumentException('A partial field name cannot contain a period.');
        }
    }
}
