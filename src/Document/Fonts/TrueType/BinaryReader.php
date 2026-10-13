<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** Bounded, big-endian access to an immutable sfnt table or font program. */
final readonly class BinaryReader
{
    private int $extent;

    public function __construct(private string $bytes, private int $origin = 0, ?int $length = null)
    {
        $size = strlen($bytes);
        if ($origin < 0 || $origin > $size || ($length !== null && ($length < 0 || $length > $size - $origin))) {
            throw new \InvalidArgumentException('A binary view must remain within its source bytes.');
        }
        $this->extent = $length ?? $size - $origin;
    }

    public function length(): int
    {
        return $this->extent;
    }

    public function slice(int $offset, int $length): string
    {
        $this->validateRange($offset, $length);
        return substr($this->bytes, $this->origin + $offset, $length);
    }

    /** A zero-copy immutable view, bounded by this reader rather than the source. */
    public function view(int $offset, int $length): self
    {
        $this->validateRange($offset, $length);
        return new self($this->bytes, $this->origin + $offset, $length);
    }

    private function validateRange(int $offset, int $length): void
    {
        if ($offset < 0 || $length < 0 || $offset > $this->length() || $length > $this->length() - $offset) {
            throw new \InvalidArgumentException('TrueType data extends beyond its containing table.');
        }
    }

    public function uint16(int $offset): int
    {
        $bytes = $this->slice($offset, 2);
        return (ord($bytes[0]) << 8) | ord($bytes[1]);
    }

    public function int16(int $offset): int
    {
        $value = $this->uint16($offset);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function uint32(int $offset): int
    {
        $bytes = $this->slice($offset, 4);
        return ord($bytes[0]) * 0x1000000 + ord($bytes[1]) * 0x10000 + ord($bytes[2]) * 0x100 + ord($bytes[3]);
    }

    public function int32(int $offset): int
    {
        $value = $this->uint32($offset);
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
