<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NumberTree;
use PDF\Syntax\Object\IntegerType;

/** Validated, immutable derived indexes for one snapshot of the explicit hierarchy. */
final readonly class StructureTreeIndex
{
    /**
     * @param list<StructureElement> $elements
     * @param array<int, MarkedContentContainer> $containers
     * @param array<int, array<int, StructureElement>> $markedParents
     * @param list<ObjectReference> $objects
     */
    public function __construct(
        public array $elements,
        public ?NumberTree $parentTree,
        public ?NameTree $identifierTree,
        public IntegerType $nextParentKey,
        public array $containers,
        public array $markedParents,
        public array $objects,
        public bool $hasUserProperties,
    ) {
    }
}
