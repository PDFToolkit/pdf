<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** PDF20_AN001-BPC.pdf p.4: effective ExtGState values, including raw resources with optional Type. */
final readonly class GraphicsStateGraphValidation
{
    public function validate(DictionaryType $dictionary, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $value = $graph->entry($dictionary, 'UseBlackPtComp');
        if ($value === null) {
            return;
        }
        $version->require(PdfVersion::PDF20, 'Black point compensation');
        $value = $graph->resolve($value);
        if (!$value instanceof NameType || BlackPointCompensation::tryFrom($value->value) === null) {
            throw new \InvalidArgumentException('UseBlackPtComp requires exactly ON, OFF or Default.');
        }
    }
}
