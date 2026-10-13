<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\StringType;

/** Explicit DER X.509 bytes referenced by PDF signatures and recipient handlers; no certificate parsing or trust claim. */
final readonly class X509Certificate
{
    public function __construct(public StringType $value)
    {
        if ($value->value === '') {
            throw new \InvalidArgumentException('A certificate byte string cannot be empty.');
        }
    }

    public function pem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($this->value->value), 64, "\n") . "-----END CERTIFICATE-----\n";
    }
}
