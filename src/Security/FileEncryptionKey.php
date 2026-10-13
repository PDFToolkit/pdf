<?php

declare(strict_types=1);

namespace PDF\Security;

/** A 256-bit file key; adobe_supplement_iso32000.pdf, PDF pages 16 and 20. */
final readonly class FileEncryptionKey
{
    public function __construct(#[\SensitiveParameter] private string $value)
    {
        if (strlen($value) !== 32) {
            throw new \InvalidArgumentException('An AES-256 key must contain exactly 32 bytes.');
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
