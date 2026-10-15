<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\UsageRight;
use PDF\Document\Signatures\Policies\UsageRightsMode;
use PDF\Document\Signatures\Policies\UsageRightsTransform;
use PDF\Syntax\FileStructure\PdfVersion;

/** Interpret the signed source version, never a later catalog upgrade (ISO1 Table255; ISO2 Table258). */
final readonly class AuthenticatedUsageRightsPolicy
{
    public function __construct(public UsageRightsTransform $transform, public PdfVersion $sourceVersion)
    {
    }

    public function allows(UsageRight $right): bool
    {
        return $this->unrestricted() || $this->transform->allows($right);
    }

    public function allowsSaving(UsageRight $operation): bool
    {
        if ($this->unrestricted()) {
            return true;
        }
        // The ISO1 P=true implicit saving paragraph was removed from ISO2.
        // In ISO2 restrictive UR3 still requires FullSave except for annotations/signing.
        return (new UsageRightsTransform(
            UsageRightsMode::ByteRange,
            $this->sourceVersion !== PdfVersion::PDF20 && $this->transform->restrictConsumers,
            null,
            ...[$operation, ...($this->transform->allows(UsageRight::FullSave) ? [UsageRight::FullSave] : [])]
        ))->allowsSaving($operation === UsageRight::ModifySignature);
    }

    private function unrestricted(): bool
    {
        return $this->sourceVersion === PdfVersion::PDF20 && !$this->transform->restrictConsumers;
    }
}
