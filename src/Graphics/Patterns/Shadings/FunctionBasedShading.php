<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\Domain;
use PDF\Functions\NumberInterval;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Specification\Definition\Type1ShadingDictionaryTable79Definition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Type 1: two independent coordinates, colour functions and an explicit target matrix (page 193). */
final class FunctionBasedShading extends ShadingDictionary
{
    public const string TABLE = '79';

    public function __construct(ColourSpace $space, ShadingFunctions $functions, public readonly Domain $domain = new Domain(new NumberInterval(new NumericType(0), new NumericType(1)), new NumberInterval(new NumericType(0), new NumericType(1))), public readonly Matrix $matrix = new Matrix(), ShadingOptions $options = new ShadingOptions())
    {
        $functions->validate(2, $space->componentCount(), $domain);
        parent::__construct(1, $space, new Type1ShadingDictionaryTable79Definition(), $options, new Entry(new NameType('Domain'), $domain), new Entry(new NameType('Matrix'), new ArrayType(...$matrix->operands())), new Entry(new NameType('Function'), $functions->value()));
    }
}
