<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\Domain;
use PDF\Functions\NumberInterval;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Specification\Definition\Type3ShadingDictionaryTable81Definition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Type 3 radial declaration and prescribed blend-circle interpolation (pages 195–196). */
final class RadialShading extends ShadingDictionary
{
    public const string TABLE = '81';

    public function __construct(ColourSpace $space, public readonly Circle $start, public readonly Circle $end, ShadingFunctions $functions, public readonly NumberInterval $domain = new NumberInterval(new NumericType(0), new NumericType(1)), public readonly ShadingExtension $extend = new ShadingExtension(), ShadingOptions $options = new ShadingOptions())
    {
        $functions->validate(1, $space->componentCount(), new Domain($domain));
        parent::__construct(3, $space, new Type3ShadingDictionaryTable81Definition(), $options, new Entry(new NameType('Coords'), new ArrayType($start->centre->x, $start->centre->y, $start->radius, $end->centre->x, $end->centre->y, $end->radius)), new Entry(new NameType('Domain'), new ArrayType($domain->minimum, $domain->maximum)), new Entry(new NameType('Extend'), $extend), new Entry(new NameType('Function'), $functions->value()));
    }

    public function circleAt(NumericType $position): ?Circle
    {
        $s = $position->value;
        if (($s < 0 && !$this->extend->start->value) || ($s > 1 && !$this->extend->end->value)) {
            return null;
        }
        $radius = $this->start->radius->value + $s * ($this->end->radius->value - $this->start->radius->value);
        if ($radius < 0) {
            return null;
        }
        return new Circle(new Point(new NumericType($this->start->centre->x->value + $s * ($this->end->centre->x->value - $this->start->centre->x->value)), new NumericType($this->start->centre->y->value + $s * ($this->end->centre->y->value - $this->start->centre->y->value))), new NumericType($radius));
    }
}
