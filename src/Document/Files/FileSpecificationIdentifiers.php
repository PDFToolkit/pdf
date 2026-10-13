<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;

/** Two byte identifiers of the referenced file; no remote file is inspected, Table44/page111. */
final class FileSpecificationIdentifiers extends ArrayType
{
    public function __construct(StringType $permanent, StringType $changing)
    {
        parent::__construct($permanent, $changing);
    }
}
