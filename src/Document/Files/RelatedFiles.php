<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

/** Table44/7.11.4.2 page113: ordered name/embedded-stream pairs, including the main file when desired. */
final class RelatedFiles extends ArrayType
{
    public function __construct(RelatedFile ...$files)
    {
        $values = [];
        foreach ($files as $file) {
            $values[] = $file->name;
            $values[] = new IndirectReference($file->stream);
        }
        parent::__construct(...$values);
    }
}
