<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;

/** Tables F.11/F.12, physical PDF page 702. */
final readonly class EmbeddedFileHintTable
{
    /** @var non-empty-list<EmbeddedFileHintGroup> */
    public array $groups;

    public function __construct(public ObjectNumber $firstObject, public ByteOffset $offset, EmbeddedFileHintGroup $first, EmbeddedFileHintGroup ...$remaining)
    {
        $this->groups = [$first, ...array_values($remaining)];
    }

    public function bytes(IntegerType $sharedIdentifierBits): string
    {
        $columns = [
            array_map(fn (EmbeddedFileHintGroup $group): int => $group->embeddedFile->value, $this->groups),
            array_map(fn (EmbeddedFileHintGroup $group): int => $group->objectCount->integer(), $this->groups),
            array_map(fn (EmbeddedFileHintGroup $group): int => $group->length->integer(), $this->groups),
            array_map(fn (EmbeddedFileHintGroup $group): int => count($group->sharedObjects), $this->groups),
        ];
        $bits = array_map(fn (array $values): IntegerType => (new HintBitWidth(new IntegerType(max($values))))->bits, $columns);
        $output = new HintBitStream();
        foreach ([[$this->firstObject->value, 32], [$this->offset->value, 32], [count($this->groups), 32]] as [$value, $width]) {
            $output->write(new HintField(new IntegerType($value), new IntegerType($width)));
        }
        foreach ($bits as $width) {
            $output->write(new HintField($width, new IntegerType(16)));
        }
        foreach ($columns as $index => $values) {
            foreach ($values as $value) {
                $output->write(new HintField(new IntegerType($value), $bits[$index]));
            }
            $output->align();
        }
        foreach ($this->groups as $group) {
            foreach ($group->sharedObjects as $reference) {
                $output->write(new HintField($reference->identifier, $sharedIdentifierBits));
            }
        }
        return $output->align()->bytes();
    }
}
