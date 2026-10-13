<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Indexed lookup with exact m*(hival+1) byte size and declared base-space scaling (pages 164–165). */
final class Indexed extends ArrayColourSpace
{
    private readonly string $lookupBytes;

    public function __construct(public readonly ColourSpace $base, public readonly IntegerType $highValue, StringType|StreamObject $lookup)
    {
        if (in_array($base->family(), [ColourSpaceFamily::Indexed, ColourSpaceFamily::Pattern], true) || $highValue->integer() < 0 || $highValue->integer() > 255) {
            throw new \InvalidArgumentException('Indexed requires a non-Indexed/non-Pattern base and hival between 0 and 255.');
        }
        if ($lookup instanceof StreamObject) {
            if (!is_string($lookup->data)) {
                throw new \InvalidArgumentException('An Indexed lookup stream must contain binary colour-table data.');
            }
            foreach ($lookup->filters as $filter) {
                if ($filter instanceof EncodedData) {
                    throw new \InvalidArgumentException('An Indexed lookup needs decoded source bytes so its exact table length can be checked.');
                }
            }
            $this->lookupBytes = $lookup->data;
        } else {
            $this->lookupBytes = $lookup->value;
        }
        if (strlen($this->lookupBytes) !== $base->componentCount() * ($highValue->integer() + 1)) {
            throw new \InvalidArgumentException('An Indexed lookup must contain exactly one byte per base component for every palette entry.');
        }
        parent::__construct(ColourSpaceFamily::Indexed, new Range(new NumberInterval(new NumericType(0), $highValue)), $base->value(), $highValue, $lookup instanceof StreamObject ? new IndirectReference($lookup) : $lookup);
    }

    public function colourAt(NumericType $index): ColourComponents
    {
        $entry = (int) max(0, min($this->highValue->integer(), floor($index->value + 0.5)));
        $values = [];
        $ranges = $this->base->ranges() ?? throw new \LogicException('An Indexed base must have component ranges.');
        foreach ($ranges->intervals as $component => $range) {
            $byte = ord($this->lookupBytes[$entry * $this->base->componentCount() + $component]);
            $values[] = new NumericType($range->minimum->value + ($range->maximum->value - $range->minimum->value) * $byte / 255);
        }
        return new ColourComponents($this->base, ...$values);
    }
}
