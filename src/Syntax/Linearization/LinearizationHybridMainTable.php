<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\IntegerType;

/** Single main subsection: physical objects are live; highest compressed IDs stay permanently hidden from old readers. */
final readonly class LinearizationHybridMainTable implements \Stringable
{
    /** @var list<ByteOffset> */
    private array $offsets;

    public function __construct(public IntegerType $compressedCount, ByteOffset ...$offsets)
    {
        if ($compressedCount->integer() < 0 || $offsets === []) {
            throw new \InvalidArgumentException('A hybrid main index has physical objects and a nonnegative compressed count.');
        }
        $this->offsets = array_values($offsets);
    }

    public function prefix(): string
    {
        return "xref\n0 " . (count($this->offsets) + $this->compressedCount->integer() + 1) . "\n";
    }

    public function __toString(): string
    {
        $firstHidden = count($this->offsets) + 1;
        $text = $this->prefix() . sprintf("%010d 65535 f \n", $this->compressedCount->integer() === 0 ? 0 : $firstHidden);
        foreach ($this->offsets as $offset) {
            $text .= sprintf("%010d 00000 n \n", $offset->value);
        }
        for ($index = 0; $index < $this->compressedCount->integer(); ++$index) {
            $next = $index + 1 === $this->compressedCount->integer() ? 0 : $firstHidden + $index + 1;
            $text .= sprintf("%010d 65535 f \n", $next);
        }
        return $text;
    }
}
