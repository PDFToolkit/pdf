<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Verifies typed content instructions and their structural associations (14.7.4, pages 568–574). */
final readonly class StructureContentValidator
{
    public function validate(StructureTreeIndex $index, bool $tagged, ?PageTreeNode $pages = null): void
    {
        $invocations = [];
        $invocationPages = [];
        $visitedPages = [];
        foreach ($index->containers as $key => $container) {
            $ids = $this->scan($container, $index, $tagged, $invocations, $invocationPages);
            $parents = $index->markedParents[$key];
            foreach ($parents as $id => $parent) {
                if (!isset($ids[$id])) {
                    throw new \InvalidArgumentException('A structural MCID has no corresponding BDC sequence in its explicit content container.');
                }
            }
            if ($tagged && count($ids) !== count($parents)) {
                throw new \InvalidArgumentException('Every Tagged PDF structural MCID must have a parent-tree association.');
            }
            if ($container->stream === null) {
                $visitedPages[spl_object_id($container->page)] = true;
            }
        }
        if ($pages !== null) {
            $queue = [$pages];
            while ($queue !== []) {
                $node = array_pop($queue);
                foreach ($node->kids() as $kid) {
                    if ($kid instanceof PageTreeNode) {
                        $queue[] = $kid;
                    } elseif ($kid instanceof PageObject && !isset($visitedPages[spl_object_id($kid)])) {
                        $key = $kid->get('StructParents');
                        $container = new MarkedContentContainer($kid, $key instanceof IntegerType ? $key : new IntegerType(0));
                        $ids = $this->scan($container, $index, $tagged, $invocations, $invocationPages);
                        if ($tagged && $ids !== []) {
                            throw new \InvalidArgumentException('A Tagged PDF page contains MCIDs absent from the structure tree.');
                        }
                    }
                }
            }
        }
        foreach ($index->containers as $container) {
            if ($container->stream !== null && (($invocations[spl_object_id($container->stream)] ?? 0) > 1)) {
                throw new \InvalidArgumentException('A repeatedly invoked form must be tagged at each invocation, rather than through internal marked-content items (PDF page 570).');
            }
            if ($container->stream !== null && $container->streamOwner === null) {
                $subtype = $container->stream->dictionary()->get('Subtype');
                if ($subtype instanceof NameType && $subtype->value === 'Form' && ($invocations[spl_object_id($container->stream)] ?? 0) !== 1) {
                    throw new \InvalidArgumentException('An internally structured form must be invoked once on its associated page.');
                }
                if ($subtype instanceof NameType && $subtype->value === 'Form' && ($invocationPages[spl_object_id($container->stream)] ?? null) !== spl_object_id($container->page)) {
                    throw new \InvalidArgumentException('A marked-content form reference must name the page on which that form is invoked.');
                }
            }
        }
        foreach ($index->objects as $reference) {
            $dictionary = $reference->object instanceof StreamObject ? $reference->object->dictionary() : $reference->object;
            $type = $dictionary->get('Type');
            if ($type instanceof NameType && $type->value === 'Annot') {
                $annotations = $this->target($reference->page->get('Annots'));
                $found = false;
                if ($annotations instanceof ArrayType) {
                    foreach ($annotations->items as $annotation) {
                        if ($this->target($annotation) === $reference->object) {
                            $found = true;
                        }
                    }
                }
                if (!$found) {
                    throw new \InvalidArgumentException('An annotation content object must occur in the associated page Annots array.');
                }
            }
        }
    }

    /**
     * @param array<int, int> $invocations
     * @param array<int, int> $invocationPages
     * @return array<int, true>
     */
    private function scan(MarkedContentContainer $container, StructureTreeIndex $index, bool $tagged, array &$invocations, array &$invocationPages): array
    {
        $ids = [];
        $scopes = [];
        $structuralDepth = 0;
        $artifactDepth = 0;
        $resources = $this->resources($container);
        foreach ($container->streams() as $stream) {
            if (!$stream->data instanceof ContentStreams) {
                throw new \InvalidArgumentException('Verified structure construction requires typed ContentStreams; opaque bytes must first be read by the optional PDF reader.');
            }
            foreach ($stream->data->operators as $operator) {
                $symbol = $operator->symbol();
                if ($operator instanceof BeginMarkedContent || $operator instanceof BeginMarkedContentWithProperties) {
                    $artifact = $operator->tag->value === 'Artifact';
                    $structural = false;
                    if ($operator instanceof BeginMarkedContentWithProperties) {
                        $properties = $operator->properties;
                        if ($properties instanceof NameType) {
                            $propertyResources = $this->target($resources->get('Properties'));
                            $properties = $propertyResources instanceof DictionaryType ? $this->target($propertyResources->get($properties)) : null;
                            if (!$properties instanceof DictionaryType) {
                                throw new \InvalidArgumentException('A named marked-content property must resolve in the current Properties resources.');
                            }
                        }
                        $mcid = $properties->get('MCID');
                        if ($mcid !== null) {
                            if (!$mcid instanceof IntegerType || $mcid->integer() < 0 || isset($ids[$mcid->integer()])) {
                                throw new \InvalidArgumentException('MCIDs must be unique non-negative integers within the combined page or auxiliary content stream.');
                            }
                            if ($artifact || $artifactDepth > 0 || $structuralDepth > 0) {
                                throw new \InvalidArgumentException('Structural content items are leaves and cannot nest within another structural item or an artifact.');
                            }
                            $ids[$mcid->integer()] = true;
                            $structural = true;
                            ++$structuralDepth;
                        }
                    }
                    if ($artifact) {
                        ++$artifactDepth;
                    }
                    $scopes[] = [$structural, $artifact];
                } elseif ($symbol === 'EMC') {
                    $scope = array_pop($scopes);
                    if ($scope === null) {
                        throw new \InvalidArgumentException('An EMC needs a corresponding marked-content beginning.');
                    }
                    $structuralDepth -= (int) $scope[0];
                    $artifactDepth -= (int) $scope[1];
                } elseif ($operator instanceof InvokeXObject) {
                    $xobjects = $this->target($resources->get('XObject'));
                    $object = $xobjects instanceof DictionaryType ? $this->target($xobjects->get($operator->resourceName)) : null;
                    if (!$object instanceof StreamObject) {
                        throw new \InvalidArgumentException('An XObject invocation must resolve to a stream resource.');
                    }
                    $objectId = spl_object_id($object);
                    $invocations[$objectId] = ($invocations[$objectId] ?? 0) + 1;
                    $invocationPages[$objectId] = spl_object_id($container->page);
                    $ownContent = $object->dictionary()->has('StructParent') || $object->dictionary()->has('StructParents');
                    if ($structuralDepth > 0 && $ownContent) {
                        throw new \InvalidArgumentException('A structural content item cannot invoke another XObject that is itself structural content.');
                    }
                    if ($tagged && $structuralDepth === 0 && $artifactDepth === 0 && !$ownContent) {
                        throw new \InvalidArgumentException('Visible Tagged PDF XObjects require structural content or an artifact classification.');
                    }
                    if ($ownContent) {
                        $parentKey = $object->dictionary()->get('StructParents') ?? $object->dictionary()->get('StructParent');
                        if (!$parentKey instanceof IntegerType || !$this->indexedObject($index, $parentKey->integer(), $object)) {
                            throw new \InvalidArgumentException('A rendered structural XObject needs a matching parent-tree association.');
                        }
                    }
                } elseif ($tagged && $structuralDepth === 0 && $artifactDepth === 0 && in_array($symbol, ['Tj', 'TJ', "'", '"', 'S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'sh', 'BI'], true)) {
                    throw new \InvalidArgumentException('Tagged PDF visible content must be enclosed in structural marked content or an Artifact sequence.');
                }
            }
        }
        if ($scopes !== []) {
            throw new \InvalidArgumentException('Marked-content sequences must be balanced within one combined page content or auxiliary stream.');
        }
        return $ids;
    }

    private function resources(MarkedContentContainer $container): DictionaryType
    {
        $resources = $container->stream?->dictionary()->get('Resources');
        if ($resources === null) {
            $resources = $container->page->effectiveResources();
        }
        $resources = $this->target($resources);
        if (!$resources instanceof DictionaryType) {
            throw new \InvalidArgumentException('A structural content stream needs its explicit or inherited resource dictionary.');
        }
        return $resources;
    }

    private function target(?BasicType $object): ?BasicType
    {
        return $object instanceof IndirectReference ? $object->target() : $object;
    }

    private function indexedObject(StructureTreeIndex $index, int $key, StreamObject $object): bool
    {
        if (isset($index->containers[$key]) && $index->containers[$key]->object() === $object) {
            return true;
        }
        foreach ($index->objects as $reference) {
            if ($reference->parentTreeKey->integer() === $key && $reference->object === $object) {
                return true;
            }
        }
        return false;
    }
}
