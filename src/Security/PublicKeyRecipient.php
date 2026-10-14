<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\StringType;

/** Opaque CMS bytes and optional declared payload permissions; this object does not validate ASN.1. */
final readonly class PublicKeyRecipient
{
    public function __construct(public StringType $value, public ?PublicKeyPermissions $permissions = null)
    {
        if ($value->value === '') {
            throw new \InvalidArgumentException('An opaque public-key recipient byte string cannot be empty.');
        }
    }
}
