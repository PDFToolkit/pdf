<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** DeviceN four/five-element array with component, tint and NChannel integrity (pages 168–174). */
final class DeviceN extends ArrayColourSpace
{
    public function __construct(public readonly ColourComponentNames $names, public readonly TintTransform $tintTransform, public readonly ?DeviceNAttributes $attributes = null, int $maximumComponents = 65536)
    {
        $count = count($names);
        if ($count !== $tintTransform->inputs || $maximumComponents < 1 || $count > $maximumComponents) {
            throw new \InvalidArgumentException('DeviceN needs one function input per named component, within its explicit construction limit.');
        }
        $seen = [];
        foreach ($names->names as $name) {
            if ($name->value === 'All' || ($name->value !== 'None' && isset($seen[$name->value]))) {
                throw new \InvalidArgumentException('DeviceN names must be unique except None; All is reserved for Separation.');
            }
            $seen[$name->value] = true;
        }
        $attributes?->validateComponents($names);
        $parameters = [$names, $tintTransform->alternate->value(), $tintTransform->value()];
        if ($attributes !== null) {
            $parameters[] = $attributes;
        }
        parent::__construct(ColourSpaceFamily::DeviceN, new Range(...array_fill(0, $count, new NumberInterval(new NumericType(0), new NumericType(1)))), ...$parameters);
    }
}
