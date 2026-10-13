<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** ISO 7.6.2 Algorithm 1, steps a–d, PDF page 66. */
final readonly class HistoricalObjectKeyDerivation
{
    public function derive(HistoricalEncryptionKey $key, ObjectIdentifier $identifier, HistoricalDataCipher $cipher): HistoricalEncryptionKey
    {
        $bytes = $key->bytes()
            . substr(pack('V', $identifier->objectNumber->value), 0, 3)
            . substr(pack('V', $identifier->generationNumber->value), 0, 2)
            . ($cipher === HistoricalDataCipher::Aes128 ? 'sAlT' : '');
        return new HistoricalEncryptionKey(substr(hash('md5', $bytes, true), 0, min(strlen($key->bytes()) + 5, 16)));
    }
}
