<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Document\DocumentGraphComponent;
use PDF\Document\DocumentGraphContext;
use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\CollectionDictionaryTable155Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Table155/pages378–379: container/initial-file selection and portable-collection presentation. */
final class Collection extends SchemaDictionary implements DocumentGraphComponent, VersionedComponent
{
    public const string TABLE = '155';

    public function __construct(?CollectionSchema $schema = null, CollectionView $view = CollectionView::Details, ?StringType $initialDocument = null, ?CollectionSort $sort = null)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('Collection')), new Entry(new NameType('View'), new NameType($view->value))];
        foreach (['Schema' => $schema, 'D' => $initialDocument, 'Sort' => $sort] as $key => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($key), $value);
            }
        }
        parent::__construct(new CollectionDictionaryTable155Definition(), ...$entries);
    }

    public function catalogEntry(): Entry
    {
        return new Entry(new NameType('Collection'), $this);
    }

    /** Null selects the containing document, including the source-prescribed fallback for an invalid D. */
    public function initialFile(DocumentGraphContext $graph): ?BasicType
    {
        $collection = $graph->dictionary($this);
        $initial = $collection?->get('D');
        $namesValue = $graph->catalog->get('Names');
        $names = $namesValue === null ? null : $graph->dictionary($namesValue);
        $embedded = $names?->get('EmbeddedFiles');
        if (!$initial instanceof StringType || $embedded === null) {
            return null;
        }
        return $graph->nameTree($embedded)[bin2hex($initial->value)] ?? null;
    }

    public function validateGraph(DocumentGraphContext $graph): void
    {
        if (!$graph->isCurrent($this) && !$graph->isReachable($this)) {
            return;
        }
        $actual = $graph->catalog->get('Collection');
        if ($actual === null || !$graph->sameObject($actual, $this)) {
            throw new \InvalidArgumentException('A native collection must describe the actual Catalog.Collection root.');
        }
        (new CollectionGraphValidation())->validate($graph);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF17, 'Portable collections');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Collections require explicit schema, view, initial document and sort arguments.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Portable collections are constructor-controlled.');
    }
}
