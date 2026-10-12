<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\IntegerType;

/** Samples in PDF order: output component varies fastest, then the first input. */
final readonly class SampleValues implements \Countable
{
    /** @var list<IntegerType> */
    public array $values;

    public function __construct(IntegerType ...$values)
    {
        foreach ($values as $value) {
            if ($value->value < 0 || $value->value > 4_294_967_295) {
                throw new \InvalidArgumentException('A sampled function value must be an unsigned integer of at most 32 bits.');
            }
        }
        $this->values = array_values($values);
    }

    public function count(): int
    {
        return count($this->values);
    }

    /** Pack continuously, most significant bit first, with zero-filled final padding. */
    public function pack(SampleBitDepth $depth): string
    {
        $maximum = (1 << $depth->value) - 1;
        $bytes = '';
        $current = 0;
        $used = 0;
        foreach ($this->values as $sample) {
            if ($sample->value > $maximum) {
                throw new \InvalidArgumentException('A sample exceeds the selected BitsPerSample.');
            }
            for ($bit = $depth->value - 1; $bit >= 0; --$bit) {
                $current = ($current << 1) | (($sample->value >> $bit) & 1);
                ++$used;
                if ($used === 8) {
                    $bytes .= chr($current & 0xff);
                    $current = 0;
                    $used = 0;
                }
            }
        }
        if ($used !== 0) {
            $bytes .= chr(($current << (8 - $used)) & 0xff);
        }

        return $bytes;
    }
}
