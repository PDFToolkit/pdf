<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

final class ZoomUsage extends UsageEntry
{
    public function __construct(public readonly NumericType $minimum = new NumericType(0), public readonly ?NumericType $maximum = null)
    {
        if ($minimum->value < 0 || ($maximum !== null && $maximum->value < $minimum->value)) {
            throw new \InvalidArgumentException('Zoom usage bounds must be nonnegative and ordered.');
        }
        $entries = [new Entry(new NameType('min'), $minimum)];
        if ($maximum !== null) {
            $entries[] = new Entry(new NameType('max'), $maximum);
        }
        parent::__construct(UsageCategory::Zoom, ...$entries);
    }

    public function recommends(NumericType $magnification): bool
    {
        return $magnification->value >= $this->minimum->value && ($this->maximum === null || $magnification->value < $this->maximum->value);
    }
}
