<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

/** Table234 RSA signing digests. DSA is constrained separately to SHA1. */
enum SignatureDigestMethod: string
{
    case SHA1 = 'SHA1';
    case SHA256 = 'SHA256';
    case SHA384 = 'SHA384';
    case SHA512 = 'SHA512';
    case RIPEMD160 = 'RIPEMD160';
}
