<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontSubsetRegistry;
use PDF\Serialization\SerializationContext;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StreamObject;

/** Relationships and subset identities are checked once in the actual, document-scoped graph. */
final class DocumentGraphValidation
{
    /** @var array<int, RevisionObject> */
    private array $effective = [];
    private readonly FontSubsetRegistry $subsets;
    private readonly DictionaryType $catalog;

    public function __construct(private readonly SerializationContext $context, DictionaryType $catalog, RevisionObject ...$effective)
    {
        $this->subsets = new FontSubsetRegistry();
        foreach ($effective as $record) {
            $this->effective[$record->identifier->objectNumber->value] = $record;
        }
        $this->catalog = $effective === [] ? $catalog : $this->effectiveDictionary($catalog);
    }

    public function validate(FileHeader $header = new FileHeader()): void
    {
        $version = new EffectivePdfVersion($header, $this->catalog);
        $pageReferences = $this->catalog->get('Pages');
        if (!$pageReferences instanceof IndirectReference) {
            throw new \LogicException('Document graph validation requires the actual Catalog.Pages reference.');
        }
        $pages = [];
        $visitedPages = [];
        $graph = new DocumentGraphContext($this->context, $this->catalog, ...array_values($this->effective));
        $this->visitPages($pageReferences, null, $pages, $visitedPages, $graph);
        (new \PDF\Document\Files\EmbeddedFilesGraphValidation())->validate($graph, $version);
        (new \PDF\Document\Collections\CollectionGraphValidation())->validate($graph, $version);
        (new \PDF\Document\Navigation\NavigationGraphValidation())->validate($graph, $version);
        /** @var list<array{BasicType, bool}> $pending */
        $pending = [[$this->catalog, true], ...array_map(static fn (BasicType $value): array => [$value, true], array_values($this->context->objects()))];
        // Unchanged objects still participate in file-scoped subset uniqueness and graph relationships.
        foreach ($this->effective as $record) {
            $pending[] = [$record->value, true];
        }
        $seen = new \SplObjectStorage();
        while ($pending !== []) {
            [$value, $indirect] = array_pop($pending);
            if ($value instanceof IndirectReference) {
                $pending[] = [$this->resolve($value), true];
                continue;
            }
            $dictionary = $this->dictionary($value);
            $type = $dictionary?->get('Type');
            if ($dictionary !== null && $type instanceof NameType && $type->value === 'Filespec') {
                (new \PDF\Document\Files\FileSpecificationRules())->validate($dictionary, $graph);
                $version->require(PdfVersion::PDF11, 'File specifications');
                foreach (['EF' => PdfVersion::PDF13, 'RF' => PdfVersion::PDF13, 'UF' => PdfVersion::PDF17, 'CI' => PdfVersion::PDF17, 'Desc' => PdfVersion::PDF16, 'V' => PdfVersion::PDF12] as $key => $minimum) {
                    if ($graph->entry($dictionary, $key) !== null) {
                        $version->require($minimum, 'File specification /' . $key);
                    }
                }
                $ci = $graph->entry($dictionary, 'CI');
                if ($ci !== null && (!$ci instanceof IndirectReference || $graph->dictionary($ci) === null)) {
                    throw new \InvalidArgumentException('File specification CI requires an indirect collection-item dictionary.');
                }
            }
            if ($dictionary !== null && $type instanceof NameType && $type->value === 'Filespec'
                && ($graph->entry($dictionary, 'EF') !== null || $graph->entry($dictionary, 'RF') !== null) && !$indirect) {
                throw new \InvalidArgumentException('A file specification containing EF or RF must be indirectly referenced.');
            }
            if ($seen->offsetExists($value)) {
                continue;
            }
            $seen->offsetSet($value);
            if ($value instanceof FontDescriptor && $value->subset !== null && $value->fontFile !== null) {
                $this->subsets->register($value->subset, $value->fontFile);
            }
            if ($value instanceof CatalogComponent && $this->isCurrent($value)) {
                $value->validateCatalog($this->catalog);
            }
            if ($value instanceof DocumentGraphComponent) {
                $value->validateGraph($graph);
            }
            if ($value instanceof VersionedComponent && $this->isCurrent($value)) {
                $value->validateVersion($version);
            }
            if ($dictionary !== null) {
                if ($value instanceof SchemaDictionary) {
                    foreach ($dictionary->entries() as $entry) {
                        if ($value->definition()->has($entry->key)) {
                            $minimum = $value->definition()->entry($entry->key)->requirement->introducedIn;
                            if ($minimum !== null && PdfVersion::tryFrom($minimum) !== null) {
                                $version->require(PdfVersion::from($minimum), 'Table ' . $value->definition()->source->table . ' /' . $entry->key->value);
                            }
                        }
                    }
                }
                $type = $dictionary->get('Type');
                $associatedPage = $dictionary->get('Pg');
                if ($type instanceof NameType && in_array($type->value, ['StructElem', 'MCR', 'OBJR'], true) && $associatedPage instanceof IndirectReference
                    && !isset($pages[$this->context->reference($associatedPage)->objectNumber->value])) {
                    throw new \InvalidArgumentException('Structure Pg must belong to the effective Catalog.Pages tree.');
                }
                $page = $dictionary->get('P');
                if ($type instanceof NameType && $type->value === 'Annot' && $page instanceof IndirectReference) {
                    $number = $this->context->reference($page)->objectNumber->value;
                    if (!isset($pages[$number])) {
                        throw new \InvalidArgumentException('Annotation P must belong to the effective Catalog.Pages tree.');
                    }
                }
                foreach ($dictionary->entries() as $entry) {
                    $pending[] = [$entry->value, false];
                }
            } elseif ($value instanceof ArrayType) {
                $array = $this->context->isHistorical($value)
                    ? ($this->effective[$this->context->identifier($value)->objectNumber->value]->snapshot ?? $value) : $value;
                if ($array instanceof ArrayType) {
                    array_push($pending, ...array_map(static fn (BasicType $item): array => [$item, false], $array->items));
                }
            }
            if ($value instanceof StreamObject && $this->isCurrent($value) && $value->data instanceof ContentStreams) {
                foreach ($value->data->operators as $operator) {
                    array_push($pending, ...array_map(static fn (BasicType $operand): array => [$operand, false], $operator->operands));
                }
            }
        }
    }

    private function resolve(IndirectReference $reference): BasicType
    {
        $identifier = $this->context->reference($reference);
        $record = $this->effective[$identifier->objectNumber->value] ?? null;
        return $record === null ? ($this->context->isHistorical($reference->target()) ? new NullType() : $reference->target()) : ((string) $identifier === (string) $record->identifier ? $record->value : new NullType());
    }

    /** Resolve frozen direct catalog containers through effective indirect bindings. */
    private function effectiveDictionary(DictionaryType $dictionary): DictionaryType
    {
        $entries = [];
        foreach ($dictionary->entries() as $entry) {
            $entries[] = new Entry($entry->key, $this->effectiveValue($entry->value));
        }
        return new DictionaryType(...$entries);
    }

    private function effectiveValue(BasicType $value): BasicType
    {
        if ($value instanceof IndirectReference) {
            $resolved = $this->resolve($value);
            return $resolved instanceof NullType ? $resolved : new IndirectReference($resolved, $this->context->reference($value));
        }
        if ($value::class === DictionaryType::class) {
            return $this->effectiveDictionary($value);
        }
        if ($value::class === ArrayType::class) {
            return new ArrayType(...array_map($this->effectiveValue(...), $value->items));
        }
        return $value;
    }

    private function isCurrent(BasicType $value): bool
    {
        if (!$this->context->isHistorical($value)) {
            return true;
        }
        return ($this->context->objects()[$this->context->identifier($value)->objectNumber->value] ?? null) === $value;
    }

    private function dictionary(BasicType $value): ?DictionaryType
    {
        if ($this->context->isHistorical($value)) {
            return $this->effective[$this->context->identifier($value)->objectNumber->value]->dictionary ?? null;
        }
        return $value instanceof DictionaryType ? $value : ($value instanceof StreamObject ? $value->dictionary() : null);
    }

    /**
     * @param array<int, true> $pages
     * @param array<int, true> $visited
     * @param array<string, BasicType> $inherited
     */
    private function visitPages(IndirectReference $reference, ?int $parent, array &$pages, array &$visited, DocumentGraphContext $graph, array $inherited = []): int
    {
        $number = $this->context->reference($reference)->objectNumber->value;
        if (isset($visited[$number])) {
            throw new \LogicException('The effective page tree cannot contain cycles or repeated children.');
        }
        $visited[$number] = true;
        $dictionary = $this->dictionary($this->resolve($reference));
        $type = $dictionary?->get('Type');
        $parentReference = $dictionary?->get('Parent');
        if (!$type instanceof NameType || !in_array($type->value, ['Page', 'Pages'], true)
            || ($parent === null ? $parentReference !== null : (!$parentReference instanceof IndirectReference || $this->context->reference($parentReference)->objectNumber->value !== $parent))) {
            throw new \LogicException('Effective page-tree nodes require consistent Type and Parent references.');
        }
        foreach (['MediaBox', 'CropBox', 'Resources', 'Rotate'] as $key) {
            $entry = $graph->entry($dictionary, $key);
            if ($entry !== null) {
                $inherited[$key] = $graph->resolve($entry);
            }
        }
        if ($type->value === 'Page') {
            if (!($inherited['Resources'] ?? null) instanceof DictionaryType) {
                throw new \LogicException('An effective page requires explicit or inherited Resources.');
            }
            foreach (['MediaBox', 'CropBox'] as $key) {
                $box = $inherited[$key] ?? null;
                if ($box === null && $key === 'CropBox') {
                    continue;
                }
                if (!$box instanceof ArrayType || count($box) !== 4) {
                    throw new \LogicException('An effective page requires valid explicit or inherited ' . $key . '.');
                }
                foreach ($box as $coordinate) {
                    if (!$graph->resolve($coordinate) instanceof NumericType) {
                        throw new \LogicException('Effective page rectangles require four numeric coordinates.');
                    }
                }
            }
            $rotation = $inherited['Rotate'] ?? null;
            if ($rotation !== null && (!$rotation instanceof NumericType || fmod($rotation->value, 90) != 0)) {
                throw new \LogicException('Effective page rotation must be an integer multiple of90.');
            }
            $pages[$number] = true;
            return 1;
        }
        $kids = $dictionary->get('Kids');
        $count = $dictionary->get('Count');
        if (!$kids instanceof ArrayType || !$count instanceof IntegerType) {
            throw new \LogicException('The effective Pages dictionary requires Kids and an integer Count.');
        }
        $leaves = 0;
        foreach ($kids->items as $kid) {
            if (!$kid instanceof IndirectReference) {
                throw new \LogicException('Page-tree children must be indirect references.');
            }
            $leaves += $this->visitPages($kid, $number, $pages, $visited, $graph, $inherited);
        }
        if ($leaves !== $count->integer()) {
            throw new \LogicException('The effective page-tree Count must match its descendant pages.');
        }
        return $leaves;
    }
}
