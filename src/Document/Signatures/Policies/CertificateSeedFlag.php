<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

enum CertificateSeedFlag: int
{
    case Subject = 1;
    case Issuer = 2;
    case PolicyOid = 4;
    case SubjectDN = 8;
    case KeyUsage = 32;
    case URL = 64;
}
