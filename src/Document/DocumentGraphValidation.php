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
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Relationships and subset identities are checked once in the actual, document-scoped graph. */
final class DocumentGraphValidation
{
    /** @var array<int, RevisionObject> */
    private array $effective = [];
    private readonly FontSubsetRegistry $subsets;

    public function __construct(private readonly SerializationContext $context, private readonly DictionaryType $catalog, RevisionObject ...$effective)
    {
        $this->subsets = new FontSubsetRegistry();
        foreach ($effective as $record) {
            $this->effective[$record->identifier->objectNumber->value] = $record;
        }
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
        $this->visitPages($pageReferences, null, $pages, $visitedPages);
        $pending = [$this->catalog, ...array_values($this->context->objects())];
        // Unchanged objects still participate in file-scoped subset uniqueness and graph relationships.
        foreach ($this->effective as $record) {
            $pending[] = $record->value;
        }
        $seen = new \SplObjectStorage();
        while ($pending !== []) {
            $value = array_pop($pending);
            if ($value instanceof IndirectReference) {
                $pending[] = $this->resolve($value);
                continue;
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
            if ($value instanceof VersionedComponent && $this->isCurrent($value)) {
                $value->validateVersion($version);
            }
            $dictionary = $this->dictionary($value);
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
                $page = $dictionary->get('P');
                if ($type instanceof NameType && $type->value === 'Annot' && $page instanceof IndirectReference) {
                    $number = $this->context->reference($page)->objectNumber->value;
                    if (!isset($pages[$number])) {
                        throw new \InvalidArgumentException('Annotation P must belong to the effective Catalog.Pages tree.');
                    }
                }
                foreach ($dictionary->entries() as $entry) {
                    $pending[] = $entry->value;
                }
            } elseif ($value instanceof ArrayType) {
                $array = $this->context->isHistorical($value)
                    ? ($this->effective[$this->context->identifier($value)->objectNumber->value]->snapshot ?? $value) : $value;
                if ($array instanceof ArrayType) {
                    array_push($pending, ...$array->items);
                }
            }
            if ($value instanceof StreamObject && $this->isCurrent($value) && $value->data instanceof ContentStreams) {
                foreach ($value->data->operators as $operator) {
                    array_push($pending, ...$operator->operands);
                }
            }
        }
    }

    private function resolve(IndirectReference $reference): BasicType
    {
        $number = $this->context->reference($reference)->objectNumber->value;
        return $this->effective[$number]->value ?? $reference->target();
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
     */
    private function visitPages(IndirectReference $reference, ?int $parent, array &$pages, array &$visited): int
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
        if ($type->value === 'Page') {
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
            $leaves += $this->visitPages($kid, $number, $pages, $visited);
        }
        if ($leaves !== $count->integer()) {
            throw new \LogicException('The effective page-tree Count must match its descendant pages.');
        }
        return $leaves;
    }
}
