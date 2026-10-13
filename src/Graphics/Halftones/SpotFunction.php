<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Functions\FunctionObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final readonly class SpotFunction
{
    public function __construct(public SpotFunctionName|FunctionObject $function)
    {
        if ($function instanceof FunctionObject) {
            if ($function->inputCount() !== 2 || $function->outputCount() !== 1) {
                throw new \InvalidArgumentException('A spot function maps two cell coordinates to one threshold-order value.');
            }
            foreach ($function->domain()->intervals as $interval) {
                if ($interval->minimum->value > -1 || $interval->maximum->value < 1) {
                    throw new \InvalidArgumentException('Spot function coordinate domains must contain [-1,1].');
                }
            }
        }
    }

    public function value(): NameType|IndirectReference
    {
        return $this->function instanceof SpotFunctionName ? new NameType($this->function->value) : new IndirectReference($this->function);
    }
}
