<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

/** Adobe Table8.83 names, including true/false as names rather than boolean objects. */
enum SignatureDocumentLock: string
{
    case Lock = 'true';
    case DoNotLock = 'false';
    case Automatic = 'auto';
}
