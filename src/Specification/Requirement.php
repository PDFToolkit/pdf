<?php

declare(strict_types=1);

namespace PDF\Specification;

/** Conditional and inherited requirements retain their normative wording. */
final readonly class Requirement
{
    public function __construct(
        public ?string $wording,
        public bool $required,
        public bool $conditional,
        public bool $inheritable,
        public ?string $introducedIn,
    ) {
    }

    public function unconditional(): bool
    {
        return $this->required && !$this->conditional && !$this->inheritable;
    }
}
