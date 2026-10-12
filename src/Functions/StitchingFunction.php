<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** FunctionType 3: partition one input domain among compatible functions. */
final class StitchingFunction extends FunctionDictionary
{
    /** @var non-empty-list<FunctionObject> */
    public readonly array $functions;
    private readonly int $outputs;

    public function __construct(Domain $domain, public readonly NumberArray $bounds, public readonly NumberArray $encode, ?Range $range = null, FunctionObject|IndirectReference ...$functions)
    {
        if ($domain->dimensions() !== 1 || $functions === []) {
            throw new \InvalidArgumentException('A stitching function needs one input and at least one subfunction.');
        }
        if (count($bounds) !== count($functions) - 1 || count($encode) !== 2 * count($functions)) {
            throw new \InvalidArgumentException('A stitching function needs k-1 bounds and 2*k encode values.');
        }
        $interval = $domain->intervals[0];
        if ($interval->minimum->value >= $interval->maximum->value && count($functions) !== 1) {
            throw new \InvalidArgumentException('A stitching domain must have positive width when it has multiple subfunctions.');
        }
        $previous = $interval->minimum->value;
        foreach ($bounds->numbers as $index => $bound) {
            if ($bound->value <= $previous || $bound->value > $interval->maximum->value || ($bound->value >= $interval->maximum->value && $index !== array_key_last($bounds->numbers))) {
                throw new \InvalidArgumentException('Stitching bounds must increase; only the last bound may equal the domain maximum.');
            }
            $previous = $bound->value;
        }
        $targets = [];
        $references = [];
        foreach ($functions as $function) {
            $target = $function instanceof IndirectReference ? $function->target() : $function;
            if (!$target instanceof FunctionObject || $target->inputCount() !== 1) {
                throw new \InvalidArgumentException('Every stitching subfunction must have exactly one input.');
            }
            if ($targets !== [] && $target->outputCount() !== $targets[0]->outputCount()) {
                throw new \InvalidArgumentException('Stitching subfunctions must have the same number of outputs.');
            }
            $targets[] = $target;
            $references[] = $function instanceof IndirectReference ? $function : new IndirectReference($function);
        }
        $this->functions = $targets;
        $this->outputs = $targets[0]->outputCount();
        if ($range !== null && $range->dimensions() !== $this->outputs) {
            throw new \InvalidArgumentException('Stitching range dimensions must match its subfunctions.');
        }
        parent::__construct(
            new IntegerType(3),
            $domain,
            $range,
            new Entry(new NameType('Functions'), new ArrayType(...$references)),
            new Entry(new NameType('Bounds'), $bounds),
            new Entry(new NameType('Encode'), $encode),
        );
    }

    public function outputCount(): int
    {
        return $this->outputs;
    }
}
