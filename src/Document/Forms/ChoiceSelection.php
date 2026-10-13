<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;

/** Explicit option indices in their required ascending order (ISO table 231). */
final readonly class ChoiceSelection
{
    /** @var list<IntegerType> */
    public array $indices;

    public function __construct(IntegerType ...$indices)
    {
        $previous = -1;
        foreach ($indices as $index) {
            if ($index->value <= $previous) {
                throw new \InvalidArgumentException('Choice selection indices must be nonnegative, unique and ascending.');
            }
            $previous = $index->value;
        }
        $this->indices = array_values($indices);
    }

    public function object(): ArrayType
    {
        return new ArrayType(...$this->indices);
    }
}
