<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\UsageRight;

/** Result of actual credential authentication; ISO1 Tables22/24, physical pages69/73–74. */
final readonly class UsageRightsSecurityPermissions
{
    public function __construct(private int $word, private bool $revision2, private bool $owner)
    {
    }

    public function allows(UsageRight $operation): bool
    {
        if ($this->owner) {
            return true;
        }
        $modify = ($this->word & 8) !== 0;
        $annotate = ($this->word & 32) !== 0;
        return match ($operation) {
            UsageRight::FillField, UsageRight::ModifySignature => $annotate || (!$this->revision2 && ($this->word & 256) !== 0),
            UsageRight::CreateAnnotation, UsageRight::ModifyAnnotation, UsageRight::DeleteAnnotation => $annotate,
            UsageRight::SpawnTemplate => $modify || (!$this->revision2 && ($this->word & 1024) !== 0),
            UsageRight::AddField, UsageRight::DeleteField => $modify && $annotate,
            default => false,
        };
    }
}
