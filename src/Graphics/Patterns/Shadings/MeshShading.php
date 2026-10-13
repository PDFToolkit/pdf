<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Actual encoded type 4–7 shading stream with immutable, validated stream dictionary. */
abstract class MeshShading extends StreamObject implements ShadingObject
{
    protected function __construct(private readonly int $type, private readonly ColourSpace $space, string $bytes, TableDefinition $definition, MeshFormat $format, ?ShadingFunctions $functions, ShadingOptions $options, ?IntegerType $verticesPerRow, Filter ...$filters)
    {
        $format->validate($space, $functions);
        foreach ($filters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('A constructed mesh contains decoded sample bytes and cannot pretend they are already encoded.');
            }
        }
        $entries = [new Entry(new NameType('ShadingType'), new IntegerType($type)), new Entry(new NameType('ColorSpace'), $space->value()), new Entry(new NameType('BitsPerCoordinate'), $format->bits->coordinate), new Entry(new NameType('BitsPerComponent'), $format->bits->component), new Entry(new NameType('Decode'), $format->decode)];
        if ($type !== 5) {
            $entries[] = new Entry(new NameType('BitsPerFlag'), $format->bits->flag);
        }
        if ($verticesPerRow !== null) {
            $entries[] = new Entry(new NameType('VerticesPerRow'), $verticesPerRow);
        }
        if ($functions !== null) {
            $entries[] = new Entry(new NameType('Function'), $functions->value());
        }
        $dictionary = new MeshShadingDictionary($definition, ...[...$entries, ...$options->entries($space)]);
        parent::__construct($bytes, $dictionary, ...$filters);
    }

    public function colourSpace(): ColourSpace
    {
        return $this->space;
    }

    public function shadingType(): int
    {
        return $this->type;
    }
}
