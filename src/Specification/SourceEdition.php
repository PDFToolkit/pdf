<?php

declare(strict_types=1);

namespace PDF\Specification;

/** Logical specification identity; independent of a supplied PDF copy and file header. */
final readonly class SourceEdition
{
    public function __construct(
        public string $identifier,
        public string $standard,
        public string $specifiedPdfVersion,
        public string $family,
        public ?ErrataCollection $corrections = null,
    ) {
        if ($identifier === '' || $standard === '' || $family === '' || !preg_match('/^[1-9]\.[0-9]+$/D', $specifiedPdfVersion)) {
            throw new \InvalidArgumentException('A source edition requires its identity, standard, PDF version and family.');
        }
    }
}
