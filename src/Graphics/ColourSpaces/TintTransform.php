<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\FunctionObject;
use PDF\Syntax\Object\IndirectReference;

/** Tint-function dimensional and domain contract (8.6.6.4–8.6.6.5, pages 166/169). */
final readonly class TintTransform
{
    public function __construct(public FunctionObject $function, public int $inputs, public ColourSpace $alternate)
    {
        if ($inputs < 1 || $alternate->family()->special() || $function->inputCount() !== $inputs || $function->outputCount() !== $alternate->componentCount()) {
            throw new \InvalidArgumentException('A tint transform must map every tint input into every component of a device or CIE-based alternate.');
        }
        foreach ($function->domain()->intervals as $domain) {
            if ($domain->minimum->value > 0 || $domain->maximum->value < 1) {
                throw new \InvalidArgumentException('A tint-transform domain must contain the complete unit interval for every input.');
            }
        }
    }

    public function value(): IndirectReference
    {
        return new IndirectReference($this->function);
    }
}
