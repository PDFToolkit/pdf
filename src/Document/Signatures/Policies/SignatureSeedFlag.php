<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

/** Table234 and Adobe Table8.83 mandatory-constraint bit masks. */
enum SignatureSeedFlag: int
{
    case Filter = 1;
    case SubFilter = 2;
    case ParserCapability = 4;
    case Reasons = 8;
    case LegalAttestation = 16;
    case Revocation = 32;
    case DigestMethod = 64;
    case LockDocument = 128;
    case AppearanceFilter = 256;
}
