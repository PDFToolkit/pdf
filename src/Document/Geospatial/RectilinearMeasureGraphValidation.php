<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** ISO1 12.9/Table262 pp488–490; ISO2 12.9.1/Table267 pp612–614. */
final readonly class RectilinearMeasureGraphValidation
{
    public function __construct(private NumberFormatGraphValidation $numberFormats = new NumberFormatGraphValidation())
    {
    }

    public function validate(DictionaryType $measure, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF16, 'Rectilinear measurements');
        $type = $this->value($measure, 'Type', $graph);
        $subtype = $this->value($measure, 'Subtype', $graph);
        if (($type !== null && (!$type instanceof NameType || $type->value !== 'Measure')) || ($subtype !== null && (!$subtype instanceof NameType || $subtype->value !== 'RL'))) {
            throw new \InvalidArgumentException('A rectilinear measure has Type Measure and Subtype RL when present.');
        }
        if (!$this->value($measure, 'R', $graph) instanceof StringType) {
            throw new \InvalidArgumentException('A rectilinear measure requires its R scale-ratio text string.');
        }
        foreach (['X', 'Y', 'D', 'A', 'T', 'S'] as $key) {
            $array = $this->value($measure, $key, $graph);
            if ($array === null && !in_array($key, ['X', 'D', 'A'], true)) {
                continue;
            }
            if (!$array instanceof ArrayType) {
                throw new \InvalidArgumentException('Rectilinear X/D/A and present Y/T/S are number-format arrays.');
            }
            $this->numberFormats->validate($array, $graph);
        }
        $origin = $this->value($measure, 'O', $graph);
        if ($origin !== null) {
            if (!$origin instanceof ArrayType || count($origin) !== 2) {
                throw new \InvalidArgumentException('A rectilinear origin O has two numeric coordinates.');
            }
            foreach ($origin->items as $coordinate) {
                if (!$graph->resolve($coordinate) instanceof NumericType) {
                    throw new \InvalidArgumentException('A rectilinear origin O has two numeric coordinates.');
                }
            }
        }
        $factor = $this->value($measure, 'CYX', $graph);
        if ($factor !== null && !$factor instanceof NumericType) {
            throw new \InvalidArgumentException('Rectilinear CYX is a numeric y-to-x conversion factor.');
        }
        // Y without CYX is valid: only distance, area and angle calculations become unavailable.
    }

    private function value(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $entry = $graph->entry($dictionary, $key);
        return $entry === null ? null : $graph->resolve($entry);
    }
}
