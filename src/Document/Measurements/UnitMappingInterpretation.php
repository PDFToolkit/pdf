<?php

declare(strict_types=1);

namespace PDF\Document\Measurements;

use PDF\Syntax\Object\NumericType;

/** Adobe supplement, pp. 59–61: callers must choose between contradictory source statements. */
enum UnitMappingInterpretation
{
    /** PDF p. 61 explicitly prints Y = (m / n) * X. */
    case PrintedEquation;

    /** PDF pp. 59–60 say m model units = n physical/display units. */
    case UnitDescription;

    public function map(NumericType $modelDistance, NumericType $m, NumericType $n): NumericType
    {
        [$numerator, $denominator] = match ($this) {
            self::PrintedEquation => [$m->value, $n->value],
            self::UnitDescription => [$n->value, $m->value],
        };
        if ($denominator == 0) {
            throw new \InvalidArgumentException('The selected unit mapping interpretation has a zero denominator.');
        }
        if ($modelDistance->value == 0 || $numerator == 0) {
            return new NumericType(0);
        }
        $ratio = $numerator / $denominator;
        if (!is_finite($ratio) || $ratio == 0) {
            throw new \OutOfRangeException('The unit mapping ratio exceeds the representable numeric range.');
        }
        $result = $ratio * $modelDistance->value;
        if (!is_finite($result) || $result == 0) {
            throw new \OutOfRangeException('The displayed distance exceeds the representable numeric range.');
        }
        return new NumericType($result);
    }
}
