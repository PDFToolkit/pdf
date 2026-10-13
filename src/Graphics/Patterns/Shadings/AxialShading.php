<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\Domain;
use PDF\Functions\NumberInterval;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Specification\Definition\Type2ShadingDictionaryTable80Definition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Type 2 axial declaration and its exact point-to-function parameter mapping (pages 193–194). */
final class AxialShading extends ShadingDictionary
{
    public const string TABLE = '80';

    public function __construct(ColourSpace $space, public readonly Point $start, public readonly Point $end, ShadingFunctions $functions, public readonly NumberInterval $domain = new NumberInterval(new NumericType(0), new NumericType(1)), public readonly ShadingExtension $extend = new ShadingExtension(), ShadingOptions $options = new ShadingOptions())
    {
        if ($start->x->value == $end->x->value && $start->y->value == $end->y->value) {
            throw new \InvalidArgumentException('An axial parameter mapping needs distinct endpoints.');
        }
        $functions->validate(1, $space->componentCount(), new Domain($domain));
        parent::__construct(2, $space, new Type2ShadingDictionaryTable80Definition(), $options, new Entry(new NameType('Coords'), new ArrayType($start->x, $start->y, $end->x, $end->y)), new Entry(new NameType('Domain'), new ArrayType($domain->minimum, $domain->maximum)), new Entry(new NameType('Extend'), $extend), new Entry(new NameType('Function'), $functions->value()));
    }

    public function parameterAt(Point $point): ?NumericType
    {
        $scale = max(abs($this->start->x->value), abs($this->start->y->value), abs($this->end->x->value), abs($this->end->y->value));
        $dx = $this->end->x->value / $scale - $this->start->x->value / $scale;
        $dy = $this->end->y->value / $scale - $this->start->y->value / $scale;
        $px = $point->x->value / $scale - $this->start->x->value / $scale;
        $py = $point->y->value / $scale - $this->start->y->value / $scale;
        $length = hypot($dx, $dy);
        if ($length == 0 || !is_finite($px) || !is_finite($py)) {
            throw new \OverflowException('The requested axial projection exceeds finite floating-point resolution.');
        }
        $position = (($dx / $length) * $px + ($dy / $length) * $py) / $length;
        if (($position < 0 && !$this->extend->start->value) || ($position > 1 && !$this->extend->end->value)) {
            return null;
        }
        $position = max(0, min(1, $position));
        return new NumericType((1 - $position) * $this->domain->minimum->value + $position * $this->domain->maximum->value);
    }
}
