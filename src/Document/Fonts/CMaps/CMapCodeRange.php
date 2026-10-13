<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

/** Numeric source interval for char/range mappings; codespace bounds are a different object. */
final readonly class CMapCodeRange
{
    public function __construct(public CMapCode $low, public CMapCode $high)
    {
        if ($low->length() !== $high->length() || strcmp($low->value->value, $high->value->value) > 0) {
            throw new \InvalidArgumentException('A mapping range has equally sized ascending source bounds.');
        }
    }

    public function distance(int $maximum = PHP_INT_MAX): int
    {
        $distance = $this->high->number() - $this->low->number();
        if ($distance > $maximum) {
            throw new \OverflowException('A character-code range exceeds its destination or runtime arithmetic bounds.');
        }
        // IEEE double represents every unsigned 32-bit integer exactly.
        return (int) $distance;
    }

    public function contains(CMapCode $code): bool
    {
        return $code->length() === $this->low->length() && strcmp($code->value->value, $this->low->value->value) >= 0 && strcmp($code->value->value, $this->high->value->value) <= 0;
    }

    public function overlaps(self $other): bool
    {
        return $other->low->length() === $this->low->length() && strcmp($this->low->value->value, $other->high->value->value) <= 0 && strcmp($other->low->value->value, $this->high->value->value) <= 0;
    }
}
