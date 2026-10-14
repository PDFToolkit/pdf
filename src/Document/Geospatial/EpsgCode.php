<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\IntegerType;

/** Tables 270/271: an authority code, without a hidden EPSG database lookup. */
final class EpsgCode extends IntegerType
{
    public function __construct(int $code)
    {
        if ($code < 1) {
            throw new \InvalidArgumentException('An EPSG reference code is positive.');
        }
        parent::__construct($code);
    }
}
