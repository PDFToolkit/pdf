<?php

declare(strict_types=1);

namespace PDF\Security;

/**
 * Caller-prepared SASLprep UTF-8 input. Prefer Saslprep::prepare() for raw input.
 * This explicit constructor does not perform SASLprep or claim that the caller did.
 * The first 127 bytes, including a possible partial UTF-8 sequence, are used by R=5.
 * adobe_supplement_iso32000.pdf, PDF pages 19–20.
 */
final readonly class PreparedPassword
{
    private string $value;

    public function __construct(#[\SensitiveParameter] string $preparedUtf8)
    {
        if (preg_match('//u', $preparedUtf8) !== 1) {
            throw new \InvalidArgumentException('Prepared password input must contain valid UTF-8 before truncation.');
        }
        $this->value = substr($preparedUtf8, 0, 127);
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
