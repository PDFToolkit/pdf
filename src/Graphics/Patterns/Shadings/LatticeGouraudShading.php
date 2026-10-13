<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Specification\Definition\Type5ShadingDictionaryTable83Definition;
use PDF\Syntax\Filters\Filter;

/** Type 5 shading whose stream encodes all rows of a complete lattice. */
final class LatticeGouraudShading extends MeshShading
{
    public function __construct(ColourSpace $space, public readonly LatticeMesh $mesh, MeshFormat $format, ?ShadingFunctions $functions = null, ShadingOptions $options = new ShadingOptions(), Filter ...$filters)
    {
        $format->validate($space, $functions);
        parent::__construct(5, $space, $mesh->bytes($format), new Type5ShadingDictionaryTable83Definition(), $format, $functions, $options, $mesh->verticesPerRow, ...$filters);
    }
}
