<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Explicit authority-scoped in-memory serial sequence; persistent authorities supply their own source. */
final class TimestampSerialSequence implements TimestampSerialSource
{
    public function __construct(private int $nextSerial = 1)
    {
        if ($nextSerial < 1) {
            throw new \InvalidArgumentException('Timestamp serial numbers are positive.');
        }
    }

    public function next(): string
    {
        if ($this->nextSerial === PHP_INT_MAX) {
            throw new \OverflowException('Timestamp serial sequence is exhausted.');
        }
        return ltrim(pack('J', $this->nextSerial++), "\0");
    }

    private function __clone(): void
    {
    }
}
