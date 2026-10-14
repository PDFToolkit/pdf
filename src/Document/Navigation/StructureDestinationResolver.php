<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Linear ordered-K resolution, ISO 32000-2 12.3.2.3, physical page 458. */
final readonly class StructureDestinationResolver
{
    /** @var array<string, IndirectReference|null> */
    private array $pagesByElement;
    private IndirectReference $firstPage;
    private ?DocumentGraphContext $graph;

    public function __construct(DictionaryType|DocumentGraphContext $document, NavigationLimits $limits = new NavigationLimits())
    {
        $this->graph = $document instanceof DocumentGraphContext ? $document : null;
        $catalog = $document instanceof DocumentGraphContext ? $document->catalog : $document;
        $pages = $this->graph === null ? array_map(static fn (DictionaryType $page): IndirectReference => new IndirectReference($page), (new CatalogPages($catalog, $limits))->pages) : array_map(static fn (array $page): BasicType => $page[0], (new NavigationPageIndex($this->graph, $limits))->pages);
        $firstPage = $pages[0] ?? null;
        if (!$firstPage instanceof IndirectReference) {
            throw new \InvalidArgumentException('A structure destination needs at least one page in the document.');
        }
        $this->firstPage = $firstPage;
        $pageKeys = [];
        foreach ($pages as $page) {
            $pageKeys[$this->key($page)] = true;
        }
        $rootValue = $catalog->get('StructTreeRoot');
        $root = $rootValue === null ? null : $this->dictionary($rootValue);
        if (!$rootValue instanceof IndirectReference || $root === null) {
            throw new \InvalidArgumentException('A local structure destination requires this catalog StructTreeRoot.');
        }
        $rootKids = $this->target($root->get('K'));
        $pending = [];
        foreach (array_reverse($rootKids instanceof ArrayType ? $rootKids->items : ($rootKids === null ? [] : [$root->get('K')])) as $kid) {
            if ($kid instanceof BasicType) {
                $pending[] = [$kid, $rootValue, 0];
            }
        }
        $elements = [];
        $order = [];
        $work = 0;
        while ($pending !== []) {
            [$reference, $parent, $depth] = array_pop($pending);
            if (++$work > $limits->maxNodes || $depth > $limits->maxDepth) {
                throw new \OverflowException('Structure-destination traversal exceeds its explicit budget.');
            }
            $key = $this->key($reference);
            $element = $this->dictionary($reference);
            $type = $this->target($element?->get('Type'));
            $role = $this->target($element?->get('S'));
            if (!$reference instanceof IndirectReference || $element === null || !$role instanceof NameType || ($type !== null && (!$type instanceof NameType || $type->value !== 'StructElem'))) {
                throw new \InvalidArgumentException('Structure destinations traverse indirect structure elements from the actual tree.');
            }
            $owner = $element->get('P');
            if (isset($elements[$key]) || !$owner instanceof IndirectReference || $this->key($owner) !== $this->key($parent)) {
                throw new \InvalidArgumentException('Structure destination hierarchy repeats an element or has an inconsistent parent.');
            }
            $kidsValue = $element->get('K');
            $kids = $this->target($kidsValue);
            $children = $kids instanceof ArrayType ? $kids->items : ($kids === null ? [] : [$kidsValue]);
            $elements[$key] = [$element, $children];
            $order[] = $key;
            foreach (array_reverse($children) as $child) {
                if (!$child instanceof BasicType) {
                    continue;
                }
                $childDictionary = $this->dictionary($child);
                $childType = $this->target($childDictionary?->get('Type'));
                if ($childDictionary !== null && ($childType === null || ($childType instanceof NameType && $childType->value === 'StructElem'))) {
                    $pending[] = [$child, $reference, $depth + 1];
                }
            }
        }
        $resolved = [];
        $contentWork = 0;
        foreach (array_reverse($order) as $key) {
            [$element, $children] = $elements[$key];
            $page = null;
            foreach ($children as $child) {
                if (!$child instanceof BasicType) {
                    throw new \InvalidArgumentException('A structure child requires a PDF value.');
                }
                if (++$contentWork > $limits->maxNodes) {
                    throw new \OverflowException('Structure-destination content inspection exceeds its explicit budget.');
                }
                $value = $this->target($child);
                $dictionary = $this->dictionary($child);
                $type = $this->target($dictionary?->get('Type'));
                if ($dictionary !== null && ($type === null || ($type instanceof NameType && $type->value === 'StructElem'))) {
                    $page = $resolved[$this->key($child)] ?? null;
                } elseif ($value instanceof IntegerType || ($type instanceof NameType && in_array($type->value, ['MCR', 'OBJR'], true))) {
                    $identifier = $value instanceof IntegerType ? $value : $this->target($dictionary?->get('MCID'));
                    if (($value instanceof IntegerType || ($type instanceof NameType && $type->value === 'MCR'))
                        && (!$identifier instanceof IntegerType || $identifier->integer() < 0)) {
                        throw new \InvalidArgumentException('A structure marked-content item requires its nonnegative integer MCID.');
                    }
                    if ($type instanceof NameType && $type->value === 'OBJR' && !$dictionary?->get('Obj') instanceof IndirectReference) {
                        throw new \InvalidArgumentException('A structure object-reference content item requires its indirect Obj.');
                    }
                    $contentPage = $dictionary?->get('Pg');
                    $page = $this->target($contentPage) === null ? $element->get('Pg') : $contentPage;
                    if (!$page instanceof IndirectReference || !isset($pageKeys[$this->key($page)])) {
                        throw new \InvalidArgumentException('A structure content item must identify an actual page in this document.');
                    }
                } else {
                    throw new \InvalidArgumentException('A structure destination child has an invalid content-item type.');
                }
                if ($page !== null) {
                    break;
                }
            }
            $resolved[$key] = $page;
        }
        $this->pagesByElement = $resolved;
    }

    public function page(ArrayType $destination): IndirectReference
    {
        $parameters = new ExplicitDestinationParameters($destination);
        $key = $this->key($parameters->reference);
        if (!array_key_exists($key, $this->pagesByElement)) {
            throw new \InvalidArgumentException('A local SD target must be a member of this effective structure tree.');
        }
        return $this->pagesByElement[$key] ?? $this->firstPage;
    }

    public static function remote(ArrayType $destination): void
    {
        $id = $destination->items[0] ?? null;
        $fit = $destination->items[1] ?? null;
        if (!$id instanceof \PDF\Syntax\Object\StringType || !$fit instanceof NameType) {
            throw new \InvalidArgumentException('A remote/embedded structure destination requires a byte-string ID, never a local indirect reference.');
        }
        new DestinationView($fit, ...array_slice($destination->items, 2));
    }

    private function key(BasicType $value): string
    {
        $id = $this->graph?->identifier($value);
        if ($id !== null) {
            return (string) $id;
        }
        return 'direct:' . spl_object_id($value instanceof IndirectReference ? $value->target() : $value);
    }

    private function target(?BasicType $value): ?BasicType
    {
        $resolved = $value === null ? null : ($this->graph?->resolve($value) ?? ($value instanceof IndirectReference ? $value->target() : $value));
        return $resolved instanceof NullType ? null : $resolved;
    }

    private function dictionary(BasicType $value): ?DictionaryType
    {
        if ($this->graph !== null) {
            return $this->graph->dictionary($value);
        }
        $value = $this->target($value);
        return $value instanceof DictionaryType ? $value : null;
    }
}
