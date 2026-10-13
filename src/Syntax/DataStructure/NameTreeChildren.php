<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

/** A nonempty, ordered Kids collection (PDF32000_2008.pdf, 7.9.6, PDF page 97). */
final readonly class NameTreeChildren
{
    /** @var non-empty-list<NameTree> */
    public array $trees;

    public function __construct(NameTree ...$trees)
    {
        if ($trees === []) {
            throw new \InvalidArgumentException('A name-tree branch needs at least one child.');
        }
        foreach ($trees as $tree) {
            if ($tree->firstKey() === null) {
                throw new \InvalidArgumentException('An empty name tree cannot be a branch child.');
            }
        }
        usort($trees, static fn (NameTree $a, NameTree $b): int => strcmp(
            ($a->firstKey() ?? throw new \LogicException('Empty child.'))->value,
            ($b->firstKey() ?? throw new \LogicException('Empty child.'))->value,
        ));
        foreach ($trees as $index => $tree) {
            if ($index > 0 && strcmp(
                ($trees[$index - 1]->lastKey() ?? throw new \LogicException('Empty child.'))->value,
                ($tree->firstKey() ?? throw new \LogicException('Empty child.'))->value,
            ) >= 0) {
                throw new \InvalidArgumentException('Name-tree child ranges must not overlap.');
            }
        }
        $this->trees = $trees;
    }
}
