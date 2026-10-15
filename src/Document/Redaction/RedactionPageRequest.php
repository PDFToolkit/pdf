<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;

/** An identity-bound explicit selection; page numbers are not global mutable state. */
final readonly class RedactionPageRequest
{
    /** @var non-empty-list<RedactionAnnotation> */
    public array $annotations;

    public function __construct(public PageObject $page, RedactionAnnotation $first, RedactionAnnotation ...$rest)
    {
        $this->annotations = [$first, ...array_values($rest)];
    }
}
