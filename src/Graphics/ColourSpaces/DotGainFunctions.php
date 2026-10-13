<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Document-scoped map of named ink functions, with no shared mutable registry. */
final class DotGainFunctions extends DictionaryType
{
    public function __construct(DotGain ...$functions)
    {
        parent::__construct(...array_map(static fn (DotGain $gain): Entry => new Entry($gain->colorant, new IndirectReference($gain->function)), $functions));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct dot-gain entries from explicit DotGain objects.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct a new dot-gain map to remove an entry.');
    }
}
