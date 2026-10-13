<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IntegerType;

/** Tables F.7/F.8, physical PDF pages 699–700. Entries follow logical page order. */
final readonly class ThumbnailHintTable
{
    /** @var non-empty-list<ThumbnailHint> */
    public array $thumbnails;

    public function __construct(public ?GenericHintTable $sharedObjects, ThumbnailHint $first, ThumbnailHint ...$remaining)
    {
        $this->thumbnails = [$first, ...array_values($remaining)];
        $previous = -1;
        foreach ($this->thumbnails as $thumbnail) {
            if ($thumbnail->pageIndex->integer() <= $previous) {
                throw new \InvalidArgumentException('Thumbnail hints have unique increasing document page indices.');
            }
            $previous = $thumbnail->pageIndex->integer();
        }
    }

    public function bytes(): string
    {
        $gaps = [];
        $counts = [];
        $lengths = [];
        $previous = -1;
        foreach ($this->thumbnails as $thumbnail) {
            $gaps[] = $thumbnail->pageIndex->integer() - $previous - 1;
            $counts[] = $thumbnail->objectCount->integer();
            $lengths[] = $thumbnail->length->integer();
            $previous = $thumbnail->pageIndex->integer();
        }
        $gapBits = (new HintBitWidth(new IntegerType(max($gaps))))->bits;
        $countMinimum = min($counts);
        $lengthMinimum = min($lengths);
        $countBits = (new HintBitWidth(new IntegerType(max($counts) - $countMinimum)))->bits;
        $lengthBits = (new HintBitWidth(new IntegerType(max($lengths) - $lengthMinimum)))->bits;
        $shared = $this->sharedObjects;
        $output = new HintBitStream();
        foreach ([
            [$this->thumbnails[0]->firstObject->value, 32], [$this->thumbnails[0]->offset->value, 32], [count($this->thumbnails), 32],
            [$gapBits->integer(), 16], [$lengthMinimum, 32], [$lengthBits->integer(), 16], [$countMinimum, 32], [$countBits->integer(), 16],
            [$shared?->firstObject->value ?? 0, 32], [$shared?->offset->value ?? 0, 32], [$shared?->objectCount->integer() ?? 0, 32], [$shared?->length->integer() ?? 0, 32],
        ] as [$value, $bits]) {
            $output->write(new HintField(new IntegerType($value), new IntegerType($bits)));
        }
        foreach ($gaps as $gap) {
            $output->write(new HintField(new IntegerType($gap), $gapBits));
        }
        $output->align();
        foreach ($counts as $count) {
            $output->write(new HintField(new IntegerType($count - $countMinimum), $countBits));
        }
        $output->align();
        foreach ($lengths as $length) {
            $output->write(new HintField(new IntegerType($length - $lengthMinimum), $lengthBits));
        }
        return $output->align()->bytes();
    }
}
