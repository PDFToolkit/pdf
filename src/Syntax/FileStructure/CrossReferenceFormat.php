<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

/** The original table or the PDF 1.5+ binary cross-reference stream. */
enum CrossReferenceFormat
{
    case Table;
    case Stream;
}
