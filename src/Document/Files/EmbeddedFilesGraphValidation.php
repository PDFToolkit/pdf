<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\IndirectReference;

/** Validate the effective attachment tree even when its former native builder was replaced. */
final readonly class EmbeddedFilesGraphValidation
{
    public function validate(DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $namesValue = $graph->entry($graph->catalog, 'Names');
        $names = $namesValue === null ? null : $graph->dictionary($namesValue);
        $embedded = $names === null ? null : $graph->entry($names, 'EmbeddedFiles');
        if ($embedded === null) {
            return;
        }
        $version->require(PdfVersion::PDF14, 'Document-level EmbeddedFiles name tree');
        foreach ($graph->nameTree($embedded) as $value) {
            $file = $graph->dictionary($value);
            if (!$value instanceof IndirectReference || $file === null || $graph->entry($file, 'EF') === null) {
                throw new \InvalidArgumentException('Every EmbeddedFiles entry requires an indirect file specification with EF.');
            }
            (new FileSpecificationRules())->validate($file, $graph);
        }
    }
}
