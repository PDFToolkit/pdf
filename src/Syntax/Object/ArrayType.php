<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;

/** @implements \IteratorAggregate<int, BasicType> */
class ArrayType implements BasicType, \Countable, \IteratorAggregate
{
    use SerializesPdf;
    /** @var list<BasicType> */
    public readonly array $items;
    public function __construct(BasicType ...$items)
    {
        $this->items = array_values($items);
    }
    public function count(): int
    {
        return count($this->items);
    }
    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }
    public function toPdf(SerializationContext $context): string
    {
        return '[' . implode(' ', array_map($context->serialize(...), $this->items)) . ']';
    }
}
