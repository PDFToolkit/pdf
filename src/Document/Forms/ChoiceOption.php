<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;

/** ISO table 231, p.453: display text and optional distinct export value. */
final readonly class ChoiceOption
{
    public function __construct(public TextString $display, public ?TextString $export = null)
    {
    }

    public function value(): TextString
    {
        return $this->export ?? $this->display;
    }

    public function object(): TextString|ArrayType
    {
        return $this->export === null ? $this->display : new ArrayType($this->export, $this->display);
    }
}
