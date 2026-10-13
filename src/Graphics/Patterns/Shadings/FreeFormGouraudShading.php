<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Specification\Definition\Type4ShadingDictionaryTable82Definition;
use PDF\Syntax\Filters\Filter;

/** Type 4 mesh, with encoded complete triangles and previous-edge connections. */
final class FreeFormGouraudShading extends MeshShading
{
    public function __construct(ColourSpace $space, public readonly FreeFormMesh $mesh, MeshFormat $format, ?ShadingFunctions $functions = null, ShadingOptions $options = new ShadingOptions(), Filter ...$filters)
    {
        $format->validate($space, $functions);
        parent::__construct(4, $space, $mesh->bytes($format), new Type4ShadingDictionaryTable82Definition(), $format, $functions, $options, null, ...$filters);
    }
}
