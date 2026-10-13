<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\StreamObject;

/** PDF 1.5 text-stream field value, ISO p.451, preserving its explicit Unicode text. */
final class FieldTextStream extends StreamObject
{
    public function __construct(public readonly TextString $text, Filter ...$filters)
    {
        parent::__construct($text->value, null, ...$filters);
    }
}
