<?php

declare(strict_types=1);

namespace PDF\Specification;

/** Provenance of one reviewed correction, not an automatically applied annotation. */
final readonly class ErrataResolution
{
    public function __construct(
        public string $id,
        public int $pdfPage,
        public ErrataReviewStatus $reviewStatus,
        public ?int $issueNumber = null,
        public ?string $annotationName = null,
    ) {
        if ($id === '' || $pdfPage < 1 || ($issueNumber !== null && $issueNumber < 1)) {
            throw new \InvalidArgumentException('An errata resolution requires an identifier, physical page and valid optional issue.');
        }
    }
}
