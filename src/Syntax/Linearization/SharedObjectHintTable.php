<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IntegerType;

/** Tables F.5/F.6, physical PDF pages 698–699; first-page groups precede other shared groups. */
final readonly class SharedObjectHintTable
{
    /** @var non-empty-list<SharedObjectGroup> */
    public array $groups;

    public function __construct(public IntegerType $firstPageGroupCount, SharedObjectGroup $first, SharedObjectGroup ...$remaining)
    {
        $this->groups = [$first, ...array_values($remaining)];
        if ($firstPageGroupCount->integer() < 1 || $firstPageGroupCount->integer() > count($this->groups)) {
            throw new \InvalidArgumentException('Every first-page object must belong to a first-page shared hint group.');
        }
        foreach ($this->groups as $index => $group) {
            if ($index === 0 || $index === $firstPageGroupCount->integer()) {
                continue;
            }
            $previous = $this->groups[$index - 1];
            if ($previous->firstObject->value + $previous->objectCount->integer() !== $group->firstObject->value
                || $previous->offset->value + $previous->length->integer() !== $group->offset->value) {
                throw new \InvalidArgumentException('Groups in each shared hint section have consecutive objects and byte ranges.');
            }
        }
    }

    public function bytes(): string
    {
        $lengths = array_map(fn (SharedObjectGroup $group): int => $group->length->integer(), $this->groups);
        $minimum = min($lengths);
        $lengthBits = (new HintBitWidth(new IntegerType(max($lengths) - $minimum)))->bits;
        $countBits = (new HintBitWidth(new IntegerType(max(array_map(fn (SharedObjectGroup $group): int => $group->objectCount->integer() - 1, $this->groups)))))->bits;
        $other = $this->groups[$this->firstPageGroupCount->integer()] ?? null;
        $output = new HintBitStream();
        foreach ([
            [$other?->firstObject->value ?? 0, 32], [$other?->offset->value ?? 0, 32],
            [$this->firstPageGroupCount->integer(), 32], [count($this->groups), 32], [$countBits->integer(), 16],
            [$minimum, 32], [$lengthBits->integer(), 16],
        ] as [$value, $bits]) {
            $output->write(new HintField(new IntegerType($value), new IntegerType($bits)));
        }
        foreach ($this->groups as $group) {
            $output->write(new HintField(new IntegerType($group->length->integer() - $minimum), $lengthBits));
        }
        $output->align();
        foreach ($this->groups as $group) {
            $output->write(new HintField(new IntegerType($group->signature === null ? 0 : 1), new IntegerType(1)));
        }
        $output->align();
        foreach ($this->groups as $group) {
            if ($group->signature !== null) {
                foreach (unpack('C*', $group->signature->value) ?: [] as $byte) {
                    $output->write(new HintField(new IntegerType($byte), new IntegerType(8)));
                }
            }
        }
        foreach ($this->groups as $group) {
            $output->write(new HintField(new IntegerType($group->objectCount->integer() - 1), $countBits));
        }
        return $output->align()->bytes();
    }
}
