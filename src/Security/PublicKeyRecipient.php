<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\StringType;

/** An opaque, non-empty CMS recipient byte string; this object does not validate ASN.1. */
final readonly class PublicKeyRecipient
{
    public function __construct(public StringType $value)
    {
        if ($value->value === '') {
            throw new \InvalidArgumentException('An opaque public-key recipient byte string cannot be empty.');
        }
    }
}
