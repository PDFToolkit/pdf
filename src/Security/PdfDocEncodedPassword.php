<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\StringType;

/**
 * Explicit bytes already encoded as PDFDocEncoding by the caller, not UTF-8 text.
 * Algorithm 3.2 in adobe_supplement_iso32000.pdf, PDF pages 18–19; inherited
 * padding/truncation is ISO 32000-1, 7.6.3.3 Algorithm 2, step 1.
 */
final readonly class PdfDocEncodedPassword
{
    private string $value;

    public function __construct(#[\SensitiveParameter] StringType $encodedBytes)
    {
        $this->value = substr($encodedBytes->value, 0, 32);
    }

    public function paddedBytes(): string
    {
        $padding = "\x28\xbf\x4e\x5e\x4e\x75\x8a\x41\x64\x00\x4e\x56\xff\xfa\x01\x08"
            . "\x2e\x2e\x00\xb6\xd0\x68\x3e\x80\x2f\x0c\xa9\xfe\x64\x53\x69\x7a";
        return $this->value . substr($padding, 0, 32 - strlen($this->value));
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }
}
