<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** Explicit ordered component-name array; family-specific uniqueness rules remain separate. */
final class ColourComponentNames extends ArrayType
{
    /** @var list<NameType> */
    public readonly array $names;

    public function __construct(NameType ...$names)
    {
        if ($names === []) {
            throw new \InvalidArgumentException('A colour component array cannot be empty.');
        }
        $this->names = array_values($names);
        parent::__construct(...$names);
    }
}
