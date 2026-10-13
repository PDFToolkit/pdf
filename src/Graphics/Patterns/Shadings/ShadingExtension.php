<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;

/** Axial/radial endpoint extension flags (tables 80–81). */
final class ShadingExtension extends ArrayType
{
    public function __construct(public readonly BooleanType $start = new BooleanType(false), public readonly BooleanType $end = new BooleanType(false))
    {
        parent::__construct($start, $end);
    }
}
