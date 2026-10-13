<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Specification\Definition\Type6ShadingDictionaryTable84Definition;
use PDF\Syntax\Filters\Filter;

/** Type 7 uses type 6 dictionary fields, with sixteen controls per complete patch (page 206). */
final class TensorProductPatchShading extends MeshShading
{
    public function __construct(ColourSpace $space, public readonly PatchMesh $mesh, MeshFormat $format, ?ShadingFunctions $functions = null, ShadingOptions $options = new ShadingOptions(), Filter ...$filters)
    {
        if ($mesh->type !== 7) {
            throw new \InvalidArgumentException('A type 7 shading requires a tensor-product patch mesh.');
        }
        $format->validate($space, $functions);
        parent::__construct(7, $space, $mesh->bytes($format), new Type6ShadingDictionaryTable84Definition(), $format, $functions, $options, null, ...$filters);
    }
}
