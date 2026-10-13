<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\IntegerType;

/** State belongs to one encoding call; prefix/byte pairs avoid storing repeated long strings. */
final class LzwEncodingTable
{
    /** @var array<int, int> */
    private array $sequences = [];
    private int $nextCode = 258;
    private int $width = 9;

    public function __construct(private readonly EarlyChange $earlyChange)
    {
    }

    public function lookup(int $prefix, int $byte): ?int
    {
        $this->validatePair($prefix, $byte);
        return $this->sequences[$prefix * 256 + $byte] ?? null;
    }

    /** True means the last available entry was created and a clear marker is required. */
    public function append(int $prefix, int $byte): bool
    {
        $this->validatePair($prefix, $byte);
        if ($this->nextCode > 4095) {
            throw new \LogicException('A LZW table entry requires a known prefix, one byte and an available code.');
        }
        $key = $prefix * 256 + $byte;
        if (isset($this->sequences[$key])) {
            throw new \LogicException('A LZW sequence cannot be appended twice.');
        }
        $this->sequences[$key] = $this->nextCode++;
        // The decoder appends its matching entry after reading the NEXT code.
        // Account for that one-entry lag; the encoder changes a code earlier.
        if ($this->width < 12 && $this->nextCode === (1 << $this->width) + 1 - $this->earlyChange->value->integer()) {
            ++$this->width;
        }
        return $this->nextCode === 4096;
    }

    public function bits(): IntegerType
    {
        return new IntegerType($this->width);
    }

    /** The decoder catches up after the final data code, before reading EOD. */
    public function endMarkerBits(): IntegerType
    {
        return new IntegerType($this->width < 12 && $this->nextCode + $this->earlyChange->value->integer() === 1 << $this->width ? $this->width + 1 : $this->width);
    }

    public function clear(): void
    {
        $this->sequences = [];
        $this->nextCode = 258;
        $this->width = 9;
    }

    private function validatePair(int $prefix, int $byte): void
    {
        if ($prefix < 0 || $prefix >= $this->nextCode || in_array($prefix, [256, 257], true) || $byte < 0 || $byte > 255) {
            throw new \InvalidArgumentException('A LZW sequence uses a known data code and one unsigned byte.');
        }
    }
}
