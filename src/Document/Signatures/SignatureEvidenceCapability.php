<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

enum SignatureEvidenceCapability
{
    case TimeStamp;
    case Revocation;
}
