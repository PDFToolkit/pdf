<?php

declare(strict_types=1);

namespace PDF\Specification;

/** Explicit implementation decision; source wording remains untouched. */
final readonly class ImplementationCorrection
{
    public function __construct(
        public string $id,
        public string $rationale,
        public string $evidenceUrl,
        public string $evidenceLocator,
    ) {
    }
}
