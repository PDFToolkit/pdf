<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

/** The effective serialized value, distinct from the construction identity retained by its aliases. */
final readonly class SerializedRevisionValue
{
    public function __construct(public BasicType $value)
    {
    }
}
