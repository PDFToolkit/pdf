<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Syntax\Object\ArrayType;

/** At most one explicit default-for-printing alternative (8.9.5.4, p.219). */
final class AlternateImages extends ArrayType
{
    public function __construct(AlternateImage ...$images)
    {
        $defaults = 0;
        foreach ($images as $image) {
            $defaults += (int) $image->defaultForPrinting->value;
        }
        if ($defaults > 1) {
            throw new \InvalidArgumentException('Only one alternate image can be the default for printing.');
        }
        parent::__construct(...$images);
    }
}
