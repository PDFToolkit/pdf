<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

final readonly class ByteOffset
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('A byte offset cannot be negative.');
        }
    }
}
