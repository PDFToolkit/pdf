<?php

declare(strict_types=1);

namespace PDF\Document\Parts;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\DataStructure\XmlName;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** ISO 32000-2 physical pp.850–853: effective DFS order, unique parents and reciprocal page ranges. */
final readonly class DocumentPartsGraphValidation
{
    public function validate(DocumentGraphContext $graph, ?EffectivePdfVersion $version = null): void
    {
        $rootValue = $graph->entry($graph->catalog, 'DPartRoot');
        if ($rootValue === null) {
            return;
        }
        $version?->require(PdfVersion::PDF20, 'Document parts');
        $root = $graph->dictionary($rootValue);
        if (!$rootValue instanceof IndirectReference || $root === null) {
            throw new \InvalidArgumentException('Catalog.DPartRoot requires an indirect document-part root.');
        }
        $this->type($root, 'DPartRoot', $graph);
        $node = $graph->entry($root, 'DPartRootNode');
        if (!$node instanceof IndirectReference) {
            throw new \InvalidArgumentException('DPartRootNode requires an indirect document-part node.');
        }
        $pageValues = [];
        $pages = $graph->entry($graph->catalog, 'Pages');
        $pendingPages = $pages === null ? [] : [$pages];
        $seenPages = [];
        while ($pendingPages !== []) {
            $page = array_pop($pendingPages);
            if (!$page instanceof IndirectReference) {
                throw new \InvalidArgumentException('Document parts require an indirect PDF page tree.');
            }
            $id = (string) $graph->identifier($page);
            if (isset($seenPages[$id]) || count($seenPages) >= 100000) {
                throw new \InvalidArgumentException('The document-part page index repeats a node or exceeds 100000 nodes.');
            }
            $seenPages[$id] = true;
            $dictionary = $graph->dictionary($page) ?? throw new \InvalidArgumentException('A part page reference must resolve to a dictionary.');
            $type = $graph->entry($dictionary, 'Type');
            $type = $type === null ? null : $graph->resolve($type);
            if ($type instanceof NameType && $type->value === 'Page') {
                $pageValues[$id] = $page;
                continue;
            }
            $kids = $graph->entry($dictionary, 'Kids');
            $kids = $kids === null ? null : $graph->resolve($kids);
            if (!$kids instanceof ArrayType) {
                throw new \InvalidArgumentException('Document parts require a valid effective page tree.');
            }
            array_push($pendingPages, ...array_reverse($kids->items));
        }
        $pageIds = array_keys($pageValues);
        $positions = array_flip($pageIds);
        $cursor = 0;
        $pending = [[$node, $rootValue, 0]];
        $seen = [];
        $maximumDepth = 0;
        while ($pending !== []) {
            [$partValue, $parent, $depth] = array_pop($pending);
            if (!$partValue instanceof IndirectReference) {
                throw new \InvalidArgumentException('DPart child references must be indirect.');
            }
            $id = (string) $graph->identifier($partValue);
            if (isset($seen[$id]) || count($seen) >= 100000 || $depth >= 1000) {
                throw new \InvalidArgumentException('The document-part hierarchy repeats a node or exceeds its explicit 100000-node/1000-level bounds.');
            }
            $seen[$id] = true;
            $maximumDepth = max($maximumDepth, $depth);
            $part = $graph->dictionary($partValue) ?? throw new \InvalidArgumentException('DPart references must resolve to dictionaries.');
            $this->type($part, 'DPart', $graph);
            $actualParent = $graph->entry($part, 'Parent');
            if (!$actualParent instanceof IndirectReference || !$graph->sameObject($actualParent, $parent)) {
                throw new \InvalidArgumentException('Every DPart requires its exact reciprocal Parent reference.');
            }
            if ($graph->entry($part, 'Metadata') !== null) {
                throw new \InvalidArgumentException('EC3 #290 forbids XMP Metadata on DPart nodes; use the DPartRoot for hierarchy metadata.');
            }
            $dpm = $graph->entry($part, 'DPM');
            if ($dpm !== null) {
                $this->metadata($dpm, $graph);
            }
            $children = $graph->entry($part, 'DParts');
            $start = $graph->entry($part, 'Start');
            $end = $graph->entry($part, 'End');
            if ($children !== null) {
                $children = $graph->resolve($children);
                if ($start !== null || $end !== null || !$children instanceof ArrayType || count($children) === 0) {
                    throw new \InvalidArgumentException('A DPart branch requires nonempty children and must omit Start and End.');
                }
                $descendants = [];
                foreach ($children as $child) {
                    if (!$child instanceof IndirectReference) {
                        throw new \InvalidArgumentException('EC3 #612 DParts is a flat array of indirect child node references.');
                    }
                    $descendants[] = [$child, $partValue, $depth + 1];
                }
                array_push($pending, ...array_reverse($descendants));
                continue;
            }
            if ($start === null) {
                if ($end !== null) {
                    throw new \InvalidArgumentException('A document-part End requires its Start page.');
                }
                continue; // Metadata-only leaves are permitted; they do not claim any page range.
            }
            if (!$start instanceof IndirectReference || ($end !== null && !$end instanceof IndirectReference)) {
                throw new \InvalidArgumentException('Document-part range endpoints must be indirect page references.');
            }
            $first = $positions[(string) $graph->identifier($start)] ?? null;
            $last = $end === null ? $first : ($positions[(string) $graph->identifier($end)] ?? null);
            if ($first === null || $last === null || $first !== $cursor || $last < $first || ($end !== null && $last === $first)) {
                throw new \InvalidArgumentException('Document-part ranges must follow page order without overlaps, gaps or redundant End keys.');
            }
            for ($index = $first; $index <= $last; ++$index) {
                $page = $graph->dictionary($pageValues[$pageIds[$index]]) ?? throw new \LogicException('A page index lost its dictionary.');
                $owner = $graph->entry($page, 'DPart');
                if (!$owner instanceof IndirectReference || !$graph->sameObject($owner, $partValue)) {
                    throw new \InvalidArgumentException('Each page requires the reciprocal DPart reference to its one owning leaf.');
                }
            }
            $cursor = $last + 1;
        }
        if ($cursor !== count($pageIds)) {
            throw new \InvalidArgumentException('The document-part hierarchy must account for each page exactly once.');
        }
        $record = $graph->entry($root, 'RecordLevel');
        $record = $record === null ? null : $graph->resolve($record);
        if ($record !== null && (!$record instanceof IntegerType || $record->integer() < 0 || $record->integer() > $maximumDepth)) {
            throw new \InvalidArgumentException('RecordLevel must identify an existing zero-based document-part level.');
        }
        $names = $graph->entry($root, 'NodeNameList');
        $names = $names === null ? null : $graph->resolve($names);
        if ($names !== null) {
            if (!$names instanceof ArrayType || count($names) !== $maximumDepth + 1) {
                throw new \InvalidArgumentException('NodeNameList must provide exactly one XML name per document-part level.');
            }
            foreach ($names as $name) {
                $name = $graph->resolve($name);
                if (!$name instanceof NameType) {
                    throw new \InvalidArgumentException('NodeNameList contains only PDF names.');
                } new XmlName($name->value);
            }
        }
    }

    private function type(DictionaryType $dictionary, string $expected, DocumentGraphContext $graph): void
    {
        $type = $graph->entry($dictionary, 'Type');
        $type = $type === null ? null : $graph->resolve($type);
        if ($type !== null && (!$type instanceof NameType || $type->value !== $expected)) {
            throw new \InvalidArgumentException('A document-part dictionary has an invalid Type.');
        }
    }

    private function metadata(\PDF\Syntax\FileStructure\BasicType $value, DocumentGraphContext $graph): void
    {
        $root = $graph->dictionary($value);
        if ($root === null) {
            throw new \InvalidArgumentException('DPM must resolve to a dictionary.');
        }
        $pending = [[$root, false, '']];
        $seen = [];
        $active = [];
        $count = 0;
        while ($pending !== []) {
            [$value, $exit, $key] = array_pop($pending);
            if ($exit) {
                unset($active[$key]);
                $seen[$key] = true;
                continue;
            }
            if (++$count > 100000) {
                throw new \LengthException('DPM validation exceeds 100000 values.');
            }
            $identifier = $graph->identifier($value);
            $key = $identifier === null ? 'direct:' . spl_object_id($value) : (string) $identifier;
            $value = $graph->resolve($value);
            if ($value instanceof DictionaryType || $value instanceof ArrayType) {
                if (isset($active[$key])) {
                    throw new \InvalidArgumentException('DPM must not contain container cycles.');
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $active[$key] = true;
                $pending[] = [$value, true, $key];
            }
            if ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    new XmlName($entry->key->value);
                    $pending[] = [$entry->value, false, ''];
                }
            } elseif ($value instanceof ArrayType) {
                foreach ($value as $item) {
                    $pending[] = [$item, false, ''];
                }
            } elseif ($value instanceof NameType) {
                if (preg_match('//u', $value->value) !== 1) {
                    throw new \InvalidArgumentException('EC3 #86 DPM name values require valid UTF-8 bytes.');
                }
            } elseif (!$value instanceof StringType && !$value instanceof BooleanType && !$value instanceof NumericType) {
                throw new \InvalidArgumentException('DPM values must be text/date strings, UTF-8 names, arrays, dictionaries, booleans or numbers.');
            }
        }
    }
}
