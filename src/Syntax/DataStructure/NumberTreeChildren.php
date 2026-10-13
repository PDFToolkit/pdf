<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

/** A nonempty, ordered Kids collection (PDF32000_2008.pdf, 7.9.7, PDF page 99). */
final readonly class NumberTreeChildren
{
    /** @var non-empty-list<NumberTree> */
    public array $trees;

    public function __construct(NumberTree ...$trees)
    {
        if ($trees === []) {
            throw new \InvalidArgumentException('A number-tree branch needs at least one child.');
        }
        foreach ($trees as $tree) {
            if ($tree->firstKey() === null) {
                throw new \InvalidArgumentException('An empty number tree cannot be a branch child.');
            }
        }
        usort(
            $trees,
            static fn (NumberTree $a, NumberTree $b): int =>
            ($a->firstKey() ?? throw new \LogicException('Empty child.'))->value
            <=> ($b->firstKey() ?? throw new \LogicException('Empty child.'))->value,
        );
        foreach ($trees as $index => $tree) {
            if ($index > 0 && ($trees[$index - 1]->lastKey() ?? throw new \LogicException('Empty child.'))->value
                >= ($tree->firstKey() ?? throw new \LogicException('Empty child.'))->value) {
                throw new \InvalidArgumentException('Number-tree child ranges must not overlap.');
            }
        }
        $this->trees = $trees;
    }
}
