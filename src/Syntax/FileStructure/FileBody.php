<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject;

final class FileBody
{
    /** @var list<BasicType|IndirectObject> */
    private array $objects = [];
    public function __construct(BasicType|IndirectObject ...$objects)
    {
        foreach ($objects as $object) {
            $this->addObject($object);
        }
    }
    public function addObject(BasicType|IndirectObject $object): self
    {
        if (!in_array($object, $this->objects, true)) {
            $this->objects[] = $object;
        }
        return $this;
    }
    /** @return list<BasicType|IndirectObject> */
    public function objects(): array
    {
        return $this->objects;
    }
}
