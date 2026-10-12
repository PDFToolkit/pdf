<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** Immutable dash array and phase (ISO 32000-1, 8.4.3.6). */
final readonly class LineDashPattern
{
    public function __construct(public ArrayType $dashArray, public NumericType $dashPhase)
    {
        $total = 0.0;
        foreach ($dashArray->items as $dash) {
            if (!$dash instanceof NumericType || $dash->value < 0) {
                throw new \InvalidArgumentException('Dash lengths must be non-negative numbers.');
            }
            $total += $dash->value;
        }
        if ($dashArray->items !== [] && $total === 0.0) {
            throw new \InvalidArgumentException('A nonempty dash array must contain at least one positive length.');
        }
        if ($dashPhase->value < 0) {
            throw new \InvalidArgumentException('The dash phase must be non-negative.');
        }
    }

    public static function solid(): self
    {
        return new self(new ArrayType(), new NumericType(0));
    }
}
