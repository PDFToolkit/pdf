<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\RecipientDecrypter;
use PDF\Syntax\Object\NameType;

/** Explicit credential override for one actual public-key crypt filter. */
final readonly class CryptFilterUsageRightsCredential
{
    public function __construct(public NameType $name, public RecipientDecrypter $decrypter)
    {
        if ($name->value === 'Identity') {
            throw new \InvalidArgumentException('Identity has no recipient credential.');
        }
    }
}
