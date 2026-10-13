<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\Signatures\SignatureFormat;
use PDF\Syntax\DataStructure\TextString;

/** Exact handler choices, bound to actual signing metadata by the writer. No cryptographic-success flag. */
final readonly class SignatureSigningChoice
{
    public function __construct(
        public SignatureHandlerCapabilities $capabilities,
        public SignatureFormat $format,
        public SignatureDigestMethod $digest,
        public ?TextString $reason = null,
        public ?DocMdpPermissions $certification = null,
        public ?TextString $legalAttestation = null,
        public ?SignatureDocumentLock $documentLock = null,
        public ?TextString $appearance = null,
        public bool $includeRevocation = false,
        public bool $includeTimeStamp = false,
    ) {
        if (!$capabilities->supports($format)) {
            throw new \InvalidArgumentException('The selected handler supports the selected signature format.');
        }
    }
}
