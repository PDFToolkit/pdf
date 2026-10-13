<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns;

use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\Patterns\Shadings\ShadingObject;
use PDF\Specification\Definition\Type2PatternDictionaryTable76Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Type 2 pattern with a real shading object and explicit pattern matrix/graphics state (page 189). */
final class ShadingPattern extends SchemaDictionary
{
    public const string TABLE = '76';

    public function __construct(public readonly ShadingObject $shading, Matrix $matrix = new Matrix(), ?DictionaryType $graphicsState = null)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('Pattern')), new Entry(new NameType('PatternType'), new IntegerType(2)), new Entry(new NameType('Shading'), new IndirectReference($shading)), new Entry(new NameType('Matrix'), new ArrayType(...$matrix->operands()))];
        if ($graphicsState !== null) {
            $entries[] = new Entry(new NameType('ExtGState'), $graphicsState);
        }
        parent::__construct(new Type2PatternDictionaryTable76Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('A shading pattern is constructed from its explicit shading, matrix and graphics state.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('A shading pattern cannot lose its defining fields.');
    }
}
