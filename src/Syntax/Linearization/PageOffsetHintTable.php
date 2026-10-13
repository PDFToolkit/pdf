<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IntegerType;

/** Tables F.3/F.4, PDF32000_2008.pdf physical pages 695–697. */
final readonly class PageOffsetHintTable
{
    /** @var non-empty-list<PageHint> */
    public array $pages;

    public function __construct(PageHint $firstPage, PageHint ...$remainingPages)
    {
        if ($firstPage->sharedObjects !== []) {
            throw new \InvalidArgumentException('The first page hint has no external shared references.');
        }
        $this->pages = [$firstPage, ...array_values($remainingPages)];
    }

    public function bytes(?IntegerType $sharedIdentifierBits = null): string
    {
        $columns = [
            array_map(fn (PageHint $page): int => $page->objectCount->integer(), $this->pages),
            array_map(fn (PageHint $page): int => $page->length->integer(), $this->pages),
            array_map(fn (PageHint $page): int => $page->contentOffset->integer(), $this->pages),
            array_map(fn (PageHint $page): int => $page->contentLength->integer(), $this->pages),
        ];
        $minimums = array_map(fn (array $values): int => min($values), $columns);
        $widths = [];
        foreach ($columns as $index => $values) {
            $widths[] = (new HintBitWidth(new IntegerType(max($values) - $minimums[$index])))->bits;
        }
        $sharedCounts = array_map(fn (PageHint $page): int => count($page->sharedObjects), $this->pages);
        $references = [];
        foreach ($this->pages as $page) {
            array_push($references, ...$page->sharedObjects);
        }
        $countBits = (new HintBitWidth(new IntegerType(max($sharedCounts))))->bits;
        $idBits = (new HintBitWidth(new IntegerType($references === [] ? 0 : max(array_map(fn (SharedObjectReference $ref): int => $ref->identifier->integer(), $references)))))->bits;
        if ($sharedIdentifierBits !== null) {
            if ($sharedIdentifierBits->integer() < $idBits->integer() || $sharedIdentifierBits->integer() > 32) {
                throw new \InvalidArgumentException('The document shared identifier width must represent every page reference.');
            }
            $idBits = $sharedIdentifierBits;
        }
        $numeratorBits = (new HintBitWidth(new IntegerType($references === [] ? 0 : max(array_map(fn (SharedObjectReference $ref): int => $ref->numerator->integer(), $references)))))->bits;
        $output = new HintBitStream();
        foreach ([
            [$minimums[0], 32], [$this->pages[0]->offset->value, 32], [$widths[0]->integer(), 16],
            [$minimums[1], 32], [$widths[1]->integer(), 16], [$minimums[2], 32], [$widths[2]->integer(), 16],
            [$minimums[3], 32], [$widths[3]->integer(), 16], [$countBits->integer(), 16], [$idBits->integer(), 16],
            [$numeratorBits->integer(), 16], [4, 16],
        ] as [$value, $bits]) {
            $output->write(new HintField(new IntegerType($value), new IntegerType($bits)));
        }
        foreach ([0, 1] as $column) {
            foreach ($columns[$column] as $value) {
                $output->write(new HintField(new IntegerType($value - $minimums[$column]), $widths[$column]));
            }
            $output->align();
        }
        foreach ($sharedCounts as $count) {
            $output->write(new HintField(new IntegerType($count), $countBits));
        }
        $output->align();
        foreach ($references as $reference) {
            $output->write(new HintField($reference->identifier, $idBits));
        }
        $output->align();
        foreach ($references as $reference) {
            $output->write(new HintField($reference->numerator, $numeratorBits));
        }
        $output->align();
        foreach ([2, 3] as $column) {
            foreach ($columns[$column] as $value) {
                $output->write(new HintField(new IntegerType($value - $minimums[$column]), $widths[$column]));
            }
            $output->align();
        }
        return $output->bytes();
    }
}
