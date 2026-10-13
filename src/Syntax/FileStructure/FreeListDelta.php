<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

/** Explicit changed historical free entries; new unused holes remain the section builder's responsibility. */
final readonly class FreeListDelta
{
    /** @var list<CrossReferenceStreamFreeEntry> */
    public array $entries;

    public function __construct(CrossReferenceStreamFreeEntry ...$entries)
    {
        $this->entries = array_values($entries);
    }
}
