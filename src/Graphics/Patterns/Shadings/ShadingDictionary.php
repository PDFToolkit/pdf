<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Validated common declarations for function-based PDF shading dictionaries. */
abstract class ShadingDictionary extends SchemaDictionary implements ShadingObject
{
    protected function __construct(private readonly int $type, private readonly ColourSpace $space, TableDefinition $definition, ShadingOptions $options, Entry ...$entries)
    {
        if (in_array($space->family(), [ColourSpaceFamily::Pattern, ColourSpaceFamily::Indexed], true)) {
            throw new \InvalidArgumentException('Function-based shadings cannot use Pattern or Indexed colour spaces.');
        }
        parent::__construct($definition, new Entry(new NameType('ShadingType'), new IntegerType($type)), new Entry(new NameType('ColorSpace'), $space->value()), ...[...$options->entries($space), ...$entries]);
    }

    public function colourSpace(): ColourSpace
    {
        return $this->space;
    }

    public function shadingType(): int
    {
        return $this->type;
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('A shading is constructed from its complete coordinate, function and colour objects.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Shading entries cannot be removed independently.');
    }
}
