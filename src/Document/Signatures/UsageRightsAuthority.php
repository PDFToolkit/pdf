<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Application policy for a verified signing identity's authority to grant usage rights; never inferred from the PDF. */
interface UsageRightsAuthority
{
    public function permits(VerifiedRevisionSignature $signature): bool;
}
