<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\DataStructure\NumberTree;
use PDF\Syntax\DataStructure\NumberTreeEntry;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Explicit ranges and real NumberTree, validated against the actual catalogue pages. */
final class PageLabels extends DictionaryType
{
    /** @var non-empty-list<LabelRange> */
    public readonly array $ranges;
    public readonly NumberTree $tree;

    public function __construct(LabelRange ...$ranges)
    {
        if ($ranges === []) {
            throw new \InvalidArgumentException('PageLabels requires a range for page index zero.');
        }
        usort($ranges, static fn (LabelRange $a, LabelRange $b): int => $a->firstPage->value <=> $b->firstPage->value);
        if ($ranges[0]->firstPage->value !== 0) {
            throw new \InvalidArgumentException('PageLabels must include page index zero.');
        }
        $this->tree = new NumberTree(...array_map(static fn (LabelRange $range): NumberTreeEntry => new NumberTreeEntry($range->firstPage, $range->label), $ranges));
        $this->ranges = $ranges;
        parent::__construct();
    }

    public function entries(): iterable
    {
        yield from $this->tree->entries();
    }

    public function label(IntegerType $pageIndex, NavigationLimits $limits = new NavigationLimits()): TextString
    {
        if ($pageIndex->value < 0) {
            throw new \InvalidArgumentException('Page indices are zero-based and nonnegative.');
        }
        $selected = $this->ranges[0];
        foreach ($this->ranges as $range) {
            if ($range->firstPage->value > $pageIndex->value) {
                break;
            }
            $selected = $range;
        }
        return $selected->label->label(new IntegerType($pageIndex->integer() - $selected->firstPage->integer()), $limits);
    }

    /** @return list<TextString> */
    public function labelsFor(DictionaryType $catalog, NavigationLimits $limits = new NavigationLimits()): array
    {
        $this->validateCatalog($catalog);
        $result = [];
        foreach ((new CatalogPages($catalog, $limits))->pages as $index => $page) {
            $result[] = $this->label(new IntegerType($index), $limits);
        }
        return $result;
    }

    public function catalogEntry(): Entry
    {
        return new Entry(new NameType('PageLabels'), $this);
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $value = $catalog->get('PageLabels');
        $value = $value instanceof IndirectReference ? $value->target() : $value;
        if ($value !== $this) {
            throw new \InvalidArgumentException('PageLabels must be the actual catalogue label tree.');
        }
        $count = count((new CatalogPages($catalog))->pages);
        foreach ($this->ranges as $range) {
            if ($range->firstPage->value >= $count) {
                throw new \InvalidArgumentException('A label range cannot start beyond the actual last page.');
            }
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('PageLabels derives its NumberTree from its ranges.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('PageLabels derives its NumberTree from its ranges.');
    }
}
