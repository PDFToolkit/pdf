<?php

declare(strict_types=1);

namespace PDF\Document;

/** Document relationships checked against effective PDF identifiers and frozen values. */
interface DocumentGraphComponent
{
    public function validateGraph(DocumentGraphContext $graph): void;
}
