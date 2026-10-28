<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Effective noninheritable page attributes: ISO1 Table30 pp85–87; ISO2 Table31 pp120–122. */
final readonly class PageAttributesGraphValidation
{
    public function validate(DictionaryType $page, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        foreach (['BleedBox', 'TrimBox', 'ArtBox'] as $key) {
            $entry = $graph->entry($page, $key);
            if ($entry === null) {
                continue;
            }
            $version->require(PdfVersion::PDF13, 'Page ' . $key);
            $rectangle = $graph->resolve($entry);
            if (!$rectangle instanceof ArrayType || count($rectangle) !== 4) {
                throw new \LogicException('Effective page ' . $key . ' requires four numeric coordinates.');
            }
            foreach ($rectangle as $coordinate) {
                if (!$graph->resolve($coordinate) instanceof NumericType) {
                    throw new \LogicException('Effective page ' . $key . ' requires four numeric coordinates.');
                }
            }
        }
        $entry = $graph->entry($page, 'UserUnit');
        if ($entry !== null) {
            $version->require(PdfVersion::PDF16, 'Page UserUnit');
            $unit = $graph->resolve($entry);
            if (!$unit instanceof NumericType || $unit->value <= 0) {
                throw new \LogicException('Effective page UserUnit must be a positive number.');
            }
        }
        $entry = $graph->entry($page, 'Tabs');
        if ($entry !== null) {
            $version->require(PdfVersion::PDF15, 'Page Tabs');
            $order = $graph->resolve($entry);
            if (!$order instanceof NameType || !in_array($order->value, ['R', 'C', 'S', 'A', 'W'], true)) {
                throw new \LogicException('Effective page Tabs must name a standard tab order.');
            }
            if (in_array($order->value, ['A', 'W'], true)) {
                $version->require(PdfVersion::PDF20, 'Page Tabs ' . $order->value);
            }
        }
    }
}
