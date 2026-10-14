<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** An AF entry for the PDF object receiving the association (14.13). */
final class AssociatedFilesEntry extends Entry
{
    public function __construct(AssociatedFiles $files)
    {
        parent::__construct(new NameType('AF'), $files);
    }
}
