<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IntegerType;

/** Table F.10, physical PDF page 701: generic group plus shared-table indices. */
final readonly class ExtendedGenericHintTable
{
    /** @var list<SharedObjectReference> */
    public array $sharedObjects;

    public function __construct(public GenericHintTable $group, SharedObjectReference ...$sharedObjects)
    {
        $this->sharedObjects = array_values($sharedObjects);
    }

    public function bytes(): string
    {
        $maximum = $this->sharedObjects === [] ? 0 : max(array_map(fn (SharedObjectReference $reference): int => $reference->identifier->integer(), $this->sharedObjects));
        $width = (new HintBitWidth(new IntegerType($maximum)))->bits;
        $output = new HintBitStream();
        $output->write(new HintField(new IntegerType(count($this->sharedObjects)), new IntegerType(32)), new HintField($width, new IntegerType(16)));
        foreach ($this->sharedObjects as $reference) {
            $output->write(new HintField($reference->identifier, $width));
        }
        return $this->group->bytes() . $output->align()->bytes();
    }
}
