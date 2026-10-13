<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceEntry;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IntegerType;

/** One consecutive Annex F subsection; only the main subsection includes free object zero. */
final readonly class LinearizationCrossReferenceTable implements \Stringable
{
    /** @var list<ByteOffset> */
    public array $offsets;

    public function __construct(public IntegerType $firstObject, ByteOffset ...$offsets)
    {
        if ($firstObject->value < 0 || ($firstObject->value !== 0 && $offsets === [])) {
            throw new \InvalidArgumentException('A linearized subsection starts at a nonnegative number and includes live records.');
        }
        $this->offsets = array_values($offsets);
    }

    public function prefix(): string
    {
        return 'xref' . "\n" . $this->firstObject->integer() . ' ' . (count($this->offsets) + ($this->firstObject->integer() === 0 ? 1 : 0)) . "\n";
    }

    public function __toString(): string
    {
        $text = $this->prefix();
        $number = $this->firstObject->integer();
        if ($number === 0) {
            $text .= new CrossReferenceEntry(0, new ByteOffset(0), new GenerationNumber(65535), false);
            ++$number;
        }
        foreach ($this->offsets as $offset) {
            $text .= new CrossReferenceEntry($number++, $offset, new GenerationNumber(0), true);
        }
        return $text;
    }
}
