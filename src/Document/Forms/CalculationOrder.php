<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

final readonly class CalculationOrder
{
    /** @var list<TerminalField> */
    public array $fields;

    public function __construct(TerminalField ...$fields)
    {
        if (count(array_unique(array_map(spl_object_id(...), $fields))) !== count($fields)) {
            throw new \InvalidArgumentException('A calculation order cannot repeat a field.');
        }
        $this->fields = array_values($fields);
    }

    public function object(): ArrayType
    {
        return new ArrayType(...array_map(static fn (TerminalField $field) => new IndirectReference($field), $this->fields));
    }
}
