<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\Object\ArrayType;

final readonly class ChoiceOptions
{
    /** @var list<ChoiceOption> */
    public array $items;

    public function __construct(ChoiceOption ...$options)
    {
        $this->items = array_values($options);
    }

    public function object(): ArrayType
    {
        return new ArrayType(...array_map(static fn (ChoiceOption $option) => $option->object(), $this->items));
    }
}
