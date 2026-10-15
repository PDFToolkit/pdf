<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\UsageRight;

/** Explicit per-update authority and operation scope. Mathematical validity alone grants nothing. */
final readonly class UsageRightsAuthorization
{
    /** @var list<UsageRight> */
    public array $rights;

    public function __construct(public UsageRightsAuthority $authority, UsageRight ...$rights)
    {
        if ($rights === [] || count(array_unique(array_map(static fn (UsageRight $right): string => $right->value, $rights))) !== count($rights)) {
            throw new \InvalidArgumentException('Usage-rights authorization selects a nonempty, distinct operation scope.');
        }
        $this->rights = array_values($rights);
    }

    public function allows(UsageRight $right): bool
    {
        return in_array($right, $this->rights, true);
    }
}
