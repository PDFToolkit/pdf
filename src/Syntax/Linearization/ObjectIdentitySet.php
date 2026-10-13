<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\BasicType;

/** An explicit identity set used while partitioning one document's graph. */
final class ObjectIdentitySet
{
    /** @var \SplObjectStorage<BasicType, null> */
    private \SplObjectStorage $objects;

    public function __construct(BasicType ...$objects)
    {
        $this->objects = new \SplObjectStorage();
        foreach ($objects as $object) {
            $this->add($object);
        }
    }

    public function add(BasicType $object): void
    {
        $this->objects->offsetSet($object);
    }

    public function has(BasicType $object): bool
    {
        return $this->objects->offsetExists($object);
    }
}
