<?php

declare(strict_types=1);

namespace PDF\Document\Annotations;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Navigation\NavigationLimits;
use PDF\Document\Navigation\NavigationPageIndex;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** ISO1 12.5.2, physical pages390–391; ISO2 pages482–483: page ownership and local names. */
final readonly class AnnotationPageIndex
{
    /** @var list<PageAnnotation> */
    public array $annotations;
    /** @var array<string,PageAnnotation> */
    private array $indirect;

    public function __construct(public DocumentGraphContext $graph, NavigationLimits $limits = new NavigationLimits())
    {
        $pages = new NavigationPageIndex($graph, $limits);
        $annotations = [];
        $indirect = [];
        $names = [];
        foreach ($pages->pages as [$page, $dictionary]) {
            $entry = $graph->entry($dictionary, 'Annots');
            if ($entry === null) {
                continue;
            }
            $array = $graph->resolve($entry);
            if (!$array instanceof ArrayType) {
                throw new \InvalidArgumentException('Page.Annots is an array of annotation dictionaries.');
            }
            $pageIdentity = $pages->key($page);
            foreach ($array->items as $position => $reference) {
                if ($graph->resolve($reference) instanceof NullType) {
                    continue; // Obsolete/free references resolve to null under ISO7.3.9.
                }
                $scope = $reference instanceof IndirectReference ? $graph : $graph->forDirectValue($reference);
                $annotation = $scope->dictionary($reference);
                if ($annotation === null) {
                    throw new \InvalidArgumentException('Page.Annots contains annotation dictionaries.');
                }
                $identifier = $scope->identifier($reference);
                $identity = $identifier === null ? $pageIdentity . '@' . $position : (string) $identifier;
                if (isset($indirect[$identity])) {
                    if ($indirect[$identity]->pageIdentity !== $pageIdentity) {
                        throw new \InvalidArgumentException('An annotation dictionary belongs to the Annots array of only one page.');
                    }
                    continue; // Repeating the same reference does not invent a second annotation identity.
                }
                if (count($annotations) >= $limits->maxNodes) {
                    throw new \LengthException('Page annotations exceed the explicit document construction budget.');
                }
                $item = new PageAnnotation($reference, $annotation, $scope, $page, $identity, $pageIdentity);
                $name = $scope->entry($annotation, 'NM');
                $name = $name === null ? null : $scope->resolve($name);
                if ($name !== null) {
                    if (!$name instanceof StringType) {
                        throw new \InvalidArgumentException('Annotation NM is a text string.');
                    }
                    $key = 'name:' . (new TextStringDecoder())->decode($name);
                    if (isset($names[$pageIdentity][$key])) {
                        throw new \InvalidArgumentException('Distinct annotations on one page have distinct NM names.');
                    }
                    $names[$pageIdentity][$key] = true;
                }
                $owner = $scope->entry($annotation, 'P');
                if ($owner !== null && (!$owner instanceof IndirectReference || !$graph->sameObject($owner, $page))) {
                    throw new \InvalidArgumentException('Annotation P identifies the page containing its Annots reference.');
                }
                $annotations[] = $item;
                if ($identifier !== null) {
                    $indirect[$identity] = $item;
                }
            }
        }
        $this->annotations = $annotations;
        $this->indirect = $indirect;
    }

    public function find(BasicType $reference): ?PageAnnotation
    {
        $identifier = $this->graph->identifier($reference);
        return $identifier === null ? null : ($this->indirect[(string) $identifier] ?? null);
    }
}
