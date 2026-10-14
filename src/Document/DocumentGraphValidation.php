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
use PDF\Syntax\FileStructure\FileRevision;
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

    public function validate(FileHeader $header = new FileHeader(), bool $encrypted = false, ?FileRevision $previous = null): void
    {
        $version = new EffectivePdfVersion($header, $this->catalog);
        $pageReferences = $this->catalog->get('Pages');
        if (!$pageReferences instanceof IndirectReference) {
            throw new \LogicException('Document graph validation requires the actual Catalog.Pages reference.');
        }
        $pages = [];
        $visitedPages = [];
        $graph = new DocumentGraphContext($this->context, $this->catalog, ...array_values($this->effective));
        $this->visitPages($pageReferences, null, $pages, $visitedPages, $graph, $version);
        $this->validatePageAssociations($pages, $graph, $version);
        (new \PDF\Document\Files\EmbeddedFilesGraphValidation())->validate($graph, $version);
        (new \PDF\Document\Collections\CollectionGraphValidation())->validate($graph, $version);
        (new \PDF\Document\Navigation\NavigationGraphValidation())->validate($graph, $version);
        (new \PDF\Document\Parts\DocumentPartsGraphValidation())->validate($graph, $version);
        (new \PDF\Document\Files\EncryptedPayloadGraphValidation())->validate($graph, $version, $encrypted);
        (new \PDF\Document\Structure\NamespaceGraphValidation())->validate($graph, $version);
        (new \PDF\Document\Signatures\DssGraphValidation())->validate($graph, $version, $previous);
        /** @var list<array{BasicType, bool}> $pending */
        $pending = [[$this->catalog, true], ...array_map(static fn (BasicType $value): array => [$value, true], array_values($this->context->objects()))];
        // Unchanged objects still participate in file-scoped subset uniqueness and graph relationships.
        foreach ($this->effective as $record) {
            $pending[] = [$record->value, true];
        }
        $seenIndirect = new \SplObjectStorage();
        $seenDirect = new \SplObjectStorage();
        while ($pending !== []) {
            [$value, $indirect] = array_pop($pending);
            if ($value instanceof IndirectReference) {
                $pending[] = [$this->resolve($value), true];
                continue;
            }
            $seen = $indirect ? $seenIndirect : $seenDirect;
            if ($seen->offsetExists($value)) {
                continue;
            }
            $seen->offsetSet($value);
            $projection = $indirect ? $graph : $graph->forDirectValue($value);
            $dictionary = $indirect ? $this->dictionary($value) : $projection->dictionary($value);
            if ($value instanceof StreamObject && $dictionary !== null) {
                (new \PDF\Document\Files\ExternalStreamsGraphValidation())->validate($dictionary, $projection, $value instanceof \PDF\Syntax\Object\ExternalStreamObject, $indirect && $this->context->wasSerializedExternalStream($value));
            }
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
            if ($value instanceof FontDescriptor && $value->subset !== null && $value->fontFile !== null) {
                $this->subsets->register($value->subset, $value->fontFile);
            }
            if ($value instanceof CatalogComponent && (!$indirect || $this->isCurrent($value))) {
                $value->validateCatalog($this->catalog);
            }
            if ($value instanceof DocumentGraphComponent) {
                $value->validateGraph($projection);
            }
            // Immutable text values retained by a revision still obey its effective version.
            if ($value instanceof VersionedComponent && (!$indirect || $this->isCurrent($value) || $value instanceof \PDF\Syntax\DataStructure\TextString)) {
                $value->validateVersion($version);
            }
            if ($dictionary !== null) {
                (new \PDF\Document\Geospatial\GeospatialGraphValidation())->validate($value, $dictionary, $projection, $version);
                (new \PDF\Document\Signatures\DocumentTimestampGraphValidation())->validate($value, $dictionary, $projection, $version, $previous);
                (new \PDF\Document\Fonts\Pdf20FontGraphValidation())->validate($value, $dictionary, $projection, $version);
                (new \PDF\Document\Metadata\MetadataGraphValidation())->validate($dictionary, $projection, $version);
                $resolvedType = $type instanceof IndirectReference ? $projection->resolve($type) : $type;
                if ($resolvedType instanceof NameType && $resolvedType->value === 'ExtGState') {
                    (new \PDF\Graphics\GraphicsState\GraphicsStateGraphValidation())->validate($dictionary, $projection, $version);
                }
                $this->validateResourceGraphicsStates($dictionary, $projection, $version);
                $this->validateMetadataContentScopes($value, $dictionary, $projection, $version);
                $associationOwner = null;
                if ($value instanceof StreamObject) {
                    $subtype = $dictionary->get('Subtype');
                    $associationOwner = $subtype instanceof NameType ? match ($subtype->value) {
                        'Form' => \PDF\Document\Files\AssociatedFileOwner::FormXObject,
                        'Image' => \PDF\Document\Files\AssociatedFileOwner::ImageXObject,
                        default => null,
                    } : null;
                }
                (new \PDF\Document\Files\AssociatedFilesGraphValidation())->validate($dictionary, $projection, $version, $associationOwner);
                if ($associationOwner === \PDF\Document\Files\AssociatedFileOwner::FormXObject && $value instanceof StreamObject) {
                    $resources = $projection->entry($dictionary, 'Resources');
                    $resourceGraph = $resources instanceof IndirectReference || $resources === null ? $projection : $projection->forDirectValue($resources);
                    $resources = $resources === null ? null : $resourceGraph->dictionary($resources);
                    if ($version->value === PdfVersion::PDF20 && $resources === null) {
                        throw new \InvalidArgumentException('PDF 2.0 Form XObjects require their own Resources dictionary (Table93, EC3 issue292).');
                    }
                    if ($value->data instanceof ContentStreams) {
                        $declared = false;
                        foreach ($value->data->operators as $operator) {
                            $declared = $declared || $operator instanceof \PDF\Document\Files\BeginAssociatedFilesMarkedContent;
                        }
                        if ($resources !== null || $declared) {
                            $this->validateMarkedContentStreams($value, $resources ?? new DictionaryType(), $projection, $version);
                        }
                    }
                }
                if ($value instanceof SchemaDictionary) {
                    if ($value->definition()->source->edition->family === 'iso32000-2') {
                        $version->require(PdfVersion::PDF20, 'ISO 32000-2 dictionary table ' . $value->definition()->source->table);
                    }
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
                $array = $indirect && $this->context->isHistorical($value)
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

    private function validateResourceGraphicsStates(DictionaryType $owner, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $resources = $graph->entry($owner, 'Resources');
        $projection = $resources instanceof IndirectReference || $resources === null ? $graph : $graph->forDirectValue($resources);
        $resources = $resources === null ? null : $projection->dictionary($resources);
        $states = $resources === null ? null : $projection->entry($resources, 'ExtGState');
        if ($states === null) {
            return;
        }
        $stateGraph = $states instanceof IndirectReference ? $projection : $projection->forDirectValue($states);
        $states = $stateGraph->dictionary($states) ?? throw new \InvalidArgumentException('Resources.ExtGState requires a dictionary.');
        foreach ($states->entries() as $entry) {
            $entryGraph = $entry->value instanceof IndirectReference ? $stateGraph : $stateGraph->forDirectValue($entry->value);
            $state = $entryGraph->dictionary($entry->value) ?? throw new \InvalidArgumentException('Every named ExtGState resource requires a graphics-state dictionary.');
            (new \PDF\Graphics\GraphicsState\GraphicsStateGraphValidation())->validate($state, $entryGraph, $version);
        }
    }

    /** @param array<int, true> $pages */
    private function validatePageAssociations(array $pages, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $associations = new \PDF\Document\Files\AssociatedFilesGraphValidation();
        foreach ($pages as $number => $_) {
            $page = $this->effective[$number]->value ?? $this->context->objects()[$number] ?? null;
            $dictionary = $page === null ? null : $graph->dictionary($page);
            $annots = $dictionary === null ? null : $graph->entry($dictionary, 'Annots');
            $annotations = $annots === null ? null : $graph->resolve($annots);
            if (!$annotations instanceof ArrayType) {
                continue;
            }
            foreach ($annotations as $annotation) {
                $projection = $annotation instanceof IndirectReference ? $graph : $graph->forDirectValue($annotation);
                $dictionary = $projection->dictionary($annotation);
                if ($dictionary !== null) {
                    (new \PDF\Document\Geospatial\GeospatialGraphValidation())->validateAnnotation($dictionary, $projection, $version);
                    if ($version->value === PdfVersion::PDF20) {
                        $associations->validate($dictionary, $projection, $version, \PDF\Document\Files\AssociatedFileOwner::Annotation);
                    }
                }
            }
        }
    }

    private function validateMarkedContentStreams(BasicType $contents, DictionaryType $resources, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $pending = [$contents];
        $seen = new \SplObjectStorage();
        $validator = new \PDF\Document\Files\AssociatedFilesMarkedContentGraphValidation();
        $metadataValidator = new \PDF\Document\Metadata\MetadataMarkedContentGraphValidation();
        while ($pending !== []) {
            $value = array_pop($pending);
            $identifier = $value instanceof StreamObject ? $graph->identifier($value) : null;
            $resolved = $identifier === null ? $graph->resolve($value) : $graph->resolve(new IndirectReference($value, $identifier));
            if ($seen->offsetExists($resolved)) {
                continue;
            }
            $seen->offsetSet($resolved);
            if ($resolved instanceof ArrayType) {
                array_push($pending, ...$resolved->items);
                continue;
            }
            if ($resolved instanceof \PDF\Syntax\FileStructure\RevisionStreamSnapshot) {
                if ($resolved->markedContentFacts !== null) {
                    $validator->validateFacts($resolved->markedContentFacts, $resources, $graph, $version);
                }
                if ($resolved->metadataMarkedContentFacts !== null) {
                    $metadataValidator->validateFacts($resolved->metadataMarkedContentFacts, $resources, $graph, $version);
                }
                continue;
            }
            // Current native operator objects carry an inspectable AST; historical
            // validation above uses operands frozen with the actual emitted bytes.
            $native = $value instanceof IndirectReference ? $this->resolve($value) : $value;
            if ($native instanceof StreamObject && $native->data instanceof ContentStreams) {
                $validator->validate($native->data, $resources, $graph, $version);
                $metadataValidator->validate($native->data, $resources, $graph, $version);
            }
        }
    }

    /** Resource scope belongs to the stream's PDF container, including retained revision evidence. */
    private function validateMetadataContentScopes(BasicType $value, DictionaryType $dictionary, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $type = $dictionary->get('Type');
        $subtype = $dictionary->get('Subtype');
        $pattern = $dictionary->get('PatternType');
        $isForm = $subtype instanceof NameType && $subtype->value === 'Form';
        $isTilingPattern = $pattern instanceof IntegerType && $pattern->integer() === 1;
        $isType3 = $type instanceof NameType && $type->value === 'Font' && $subtype instanceof NameType && $subtype->value === 'Type3';
        if (!$isForm && !$isTilingPattern && !$isType3) {
            return;
        }
        $resources = $graph->entry($dictionary, 'Resources');
        $projection = $resources instanceof IndirectReference || $resources === null ? $graph : $graph->forDirectValue($resources);
        $resources = $resources === null ? null : $projection->dictionary($resources);
        if ($resources === null) {
            return;
        }
        if (!$isType3) {
            if ($value instanceof StreamObject) {
                $this->validateMarkedContentStreams($value, $resources, $graph, $version);
            }
            return;
        }
        $procedures = $graph->entry($dictionary, 'CharProcs');
        $procedureGraph = $procedures instanceof IndirectReference || $procedures === null ? $graph : $graph->forDirectValue($procedures);
        $procedures = $procedures === null ? null : $procedureGraph->dictionary($procedures);
        foreach ($procedures?->entries() ?? [] as $entry) {
            $this->validateMarkedContentStreams($entry->value, $resources, $graph, $version);
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
    private function visitPages(IndirectReference $reference, ?int $parent, array &$pages, array &$visited, DocumentGraphContext $graph, EffectivePdfVersion $version, array $inherited = []): int
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
            $contents = $graph->entry($dictionary, 'Contents');
            if ($contents !== null) {
                $this->validateMarkedContentStreams($contents, $inherited['Resources'], $graph, $version);
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
            $leaves += $this->visitPages($kid, $number, $pages, $visited, $graph, $version, $inherited);
        }
        if ($leaves !== $count->integer()) {
            throw new \LogicException('The effective page-tree Count must match its descendant pages.');
        }
        return $leaves;
    }
}
