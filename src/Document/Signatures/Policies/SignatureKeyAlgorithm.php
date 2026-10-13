<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

enum SignatureKeyAlgorithm
{
    case RSA;
    case DSA;
    case Other;
}
