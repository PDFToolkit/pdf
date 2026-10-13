<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\FunctionObject;
use PDF\Syntax\Object\NameType;

/** Explicit named one-input/one-output ink dot-gain mapping (table 73, PDF page 172). */
final readonly class DotGain
{
    public function __construct(public NameType $colorant, public FunctionObject $function)
    {
        if ($function->inputCount() !== 1 || $function->outputCount() !== 1 || $function->domain()->intervals[0]->minimum->value > 0 || $function->domain()->intervals[0]->maximum->value < 1) {
            throw new \InvalidArgumentException('A dot-gain function maps the complete unit interval into one output component.');
        }
        $range = $function->range();
        if ($range !== null && ($range->intervals[0]->minimum->value < 0 || $range->intervals[0]->maximum->value > 1)) {
            throw new \InvalidArgumentException('An explicit dot-gain output range must stay within zero and one.');
        }
    }
}
