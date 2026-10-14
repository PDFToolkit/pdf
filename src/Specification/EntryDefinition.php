<?php

declare(strict_types=1);

namespace PDF\Specification;

final readonly class EntryDefinition
{
    public function __construct(
        public string $name,
        public ObjectTypeConstraint $type,
        public Requirement $requirement,
        public SourceReference $source,
        public string $description,
        public bool $variable = false,
        public ?ImplementationCorrection $correction = null,
        public ?ErrataResolution $errata = null,
    ) {
    }
}
