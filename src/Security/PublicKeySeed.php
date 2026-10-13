<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\StringType;

/** The explicit 20-byte recipient seed; no certificate/CMS decoding is performed. */
final readonly class PublicKeySeed
{
    private string $value;

    public function __construct(#[\SensitiveParameter] StringType $seed)
    {
        if (strlen($seed->value) !== 20) {
            throw new \InvalidArgumentException('The public-key encryption seed must contain exactly 20 bytes.');
        }
        $this->value = $seed->value;
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
