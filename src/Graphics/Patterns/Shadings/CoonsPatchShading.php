<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Specification\Definition\Type6ShadingDictionaryTable84Definition;
use PDF\Syntax\Filters\Filter;

/** Type 6 encoded Coons mesh, including implicit shared-edge point/colour reuse. */
final class CoonsPatchShading extends MeshShading
{
    public function __construct(ColourSpace $space, public readonly PatchMesh $mesh, MeshFormat $format, ?ShadingFunctions $functions = null, ShadingOptions $options = new ShadingOptions(), Filter ...$filters)
    {
        if ($mesh->type !== 6) {
            throw new \InvalidArgumentException('A type 6 shading requires a Coons patch mesh.');
        }
        $format->validate($space, $functions);
        parent::__construct(6, $space, $mesh->bytes($format), new Type6ShadingDictionaryTable84Definition(), $format, $functions, $options, null, ...$filters);
    }
}
