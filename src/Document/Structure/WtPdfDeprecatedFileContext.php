<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\DictionaryType;

/** Application-supplied trailer of the constructed graph, not authentication of imported file bytes. */
final readonly class WtPdfDeprecatedFileContext
{
    public function __construct(public DictionaryType $trailer)
    {
    }
}
