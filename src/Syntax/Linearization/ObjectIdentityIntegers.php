<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\BasicType;

/** A plan-scoped identity map for calculated numbers, indices or offsets. */
final class ObjectIdentityIntegers
{
    /** @var \SplObjectStorage<BasicType, int> */
    private \SplObjectStorage $values;

    public function __construct()
    {
        $this->values = new \SplObjectStorage();
    }

    public function assign(BasicType $object, int $value): void
    {
        $this->values[$object] = $value;
    }

    public function has(BasicType $object): bool
    {
        return $this->values->offsetExists($object);
    }

    public function value(BasicType $object): int
    {
        if (!$this->values->offsetExists($object)) {
            throw new \OutOfRangeException('The object has no value in this linearization plan.');
        }
        return $this->values[$object];
    }
}
