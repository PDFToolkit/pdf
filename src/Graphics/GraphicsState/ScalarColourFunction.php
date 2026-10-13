<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Functions\FunctionObject;
use PDF\Syntax\Object\IndirectReference;

/** Shared one-input/one-output colour-function dimensional and unit-input contract. */
final readonly class ScalarColourFunction
{
    public function __construct(public FunctionObject $function)
    {
        $this->validate();
    }

    public function validate(): void
    {
        $domain = $this->function->domain()->intervals;
        if ($this->function->inputCount() !== 1 || $this->function->outputCount() !== 1 || $domain[0]->minimum->value > 0 || $domain[0]->maximum->value < 1) {
            throw new \InvalidArgumentException('A scalar colour function must accept every unit input and produce one output.');
        }
    }

    public function value(): IndirectReference
    {
        return new IndirectReference($this->function);
    }
}
