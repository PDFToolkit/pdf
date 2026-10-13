<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureDocumentLock;
use PDF\Syntax\DataStructure\TextString;

/** Construction choices, never certificate/evidence success assertions. Frozen metadata is checked again. */
final readonly class SignatureSigningIntent
{
    public function __construct(
        public ?SignatureDigestMethod $digest = null,
        public ?TextString $reason = null,
        public ?DocMdpPermissions $certification = null,
        public ?TextString $legalAttestation = null,
        public ?SignatureDocumentLock $documentLock = null,
        public ?SignatureAppearanceSelection $appearance = null,
        public bool $includeRevocation = false,
        public bool $includeTimeStamp = false,
    ) {
    }
}
