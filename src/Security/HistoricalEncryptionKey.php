<?php

declare(strict_types=1);

namespace PDF\Security;

/** A derived legacy 40–128-bit file key; no RC4/AES-128 writer is selected. */
final readonly class HistoricalEncryptionKey
{
    public function __construct(#[\SensitiveParameter] private string $value)
    {
        if (strlen($value) < 5 || strlen($value) > 16) {
            throw new \InvalidArgumentException('A legacy file key must contain 5–16 bytes.');
        }
    }

    public function bytes(): string
    {
        return $this->value;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }
}
