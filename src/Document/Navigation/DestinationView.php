<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

/** Eight view parameter forms, ISO 32000-2 Table 149, physical pages 456–457. */
final readonly class DestinationView
{
    /** @var list<NumericType|NullType> */
    public array $coordinates;

    public function __construct(public NameType $fit, BasicType ...$coordinates)
    {
        $arity = match ($fit->value) {
            'Fit', 'FitB' => 0,
            'FitH', 'FitV', 'FitBH', 'FitBV' => 1,
            'XYZ' => 3,
            'FitR' => 4,
            default => throw new \InvalidArgumentException('The explicit destination mode is unknown.'),
        };
        if (count($coordinates) !== $arity) {
            throw new \InvalidArgumentException('The destination has the wrong number of coordinates.');
        }
        $values = [];
        foreach ($coordinates as $coordinate) {
            if (!$coordinate instanceof NumericType && !($fit->value !== 'FitR' && $coordinate instanceof NullType)) {
                throw new \InvalidArgumentException('Destination coordinates are numbers or permitted null parameters.');
            }
            $values[] = $coordinate;
        }
        if ($fit->value === 'XYZ' && $values[2] instanceof NumericType && $values[2]->value < 0) {
            throw new \InvalidArgumentException('Destination zoom cannot be negative.');
        }
        $this->coordinates = $values;
    }
}
