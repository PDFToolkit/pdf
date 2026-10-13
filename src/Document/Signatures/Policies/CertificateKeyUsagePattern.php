<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\DataStructure\AsciiString;

/** Table235: only first nine ASCII characters matter; missing/unknown characters are wildcards. */
final readonly class CertificateKeyUsagePattern
{
    public function __construct(public AsciiString $value)
    {
    }
    public function matches(CertificateKeyUsage $usage): bool
    {
        for ($index = 0; $index < 9; ++$index) {
            $required = $this->value->value[$index] ?? 'X';
            if (($required === '1' && !$usage->set($index)) || ($required === '0' && $usage->set($index))) {
                return false;
            }
        }
        return true;
    }
}
