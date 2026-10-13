<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\BasicType;

/** Identity-preserving logical-to-physical mapping for one linearization plan. */
final class ObjectIdentityObjects
{
    /** @var \SplObjectStorage<BasicType, BasicType> */
    private \SplObjectStorage $values;

    public function __construct()
    {
        $this->values = new \SplObjectStorage();
    }

    public function assign(BasicType $logical, BasicType $physical): void
    {
        $this->values[$logical] = $physical;
    }

    public function has(BasicType $logical): bool
    {
        return $this->values->offsetExists($logical);
    }

    public function value(BasicType $logical): BasicType
    {
        return $this->values->offsetExists($logical) ? $this->values[$logical] : $logical;
    }
}
