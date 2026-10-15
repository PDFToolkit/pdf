<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

final readonly class CrossReferenceIndex implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $widths = $values->numbers($dictionary, 'W');
        if ($widths !== null && ($widths[1] ?? 0) === 0) {
            throw new \InvalidArgumentException('The second /W width shall be nonzero.');
        }
        $size = $values->value($dictionary, 'Size');
        $index = $values->numbers($dictionary, 'Index');
        if ($index === null) {
            return;
        }
        $end = 0;
        for ($position = 0; $position < count($index); $position += 2) {
            $start = $index[$position];
            $count = $index[$position + 1] ?? throw new \InvalidArgumentException('/Index requires subsection pairs.');
            if (!is_int($start) || !is_int($count) || $start < $end || $count < 0 || $start > PHP_INT_MAX - $count) {
                throw new \InvalidArgumentException('/Index subsections shall be nonnegative, ordered and nonoverlapping.');
            }
            $end = $start + $count;
            if ($size instanceof IntegerType && $end > $size->integer()) {
                throw new \InvalidArgumentException('/Index subsection exceeds /Size.');
            }
        }
    }
}
