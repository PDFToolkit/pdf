<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\BasicType;

/** The frozen serialized object bytes belonging to one layout operation. */
final class ObjectIdentityBytes
{
    /** @var \SplObjectStorage<BasicType, string> */
    private \SplObjectStorage $values;

    public function __construct()
    {
        $this->values = new \SplObjectStorage();
    }

    public function assign(BasicType $object, string $value): void
    {
        $this->values[$object] = $value;
    }

    public function value(BasicType $object): string
    {
        if (!$this->values->offsetExists($object)) {
            throw new \OutOfRangeException('The object has no frozen bytes in this linearization plan.');
        }
        return $this->values[$object];
    }
}
