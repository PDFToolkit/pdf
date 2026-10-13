<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\IndirectReference;

/** [ /ICCBased profile-stream-reference ], PDF page 157. */
final class ICCBased extends ArrayColourSpace
{
    public function __construct(public readonly ICCProfile $profile)
    {
        parent::__construct(ColourSpaceFamily::ICCBased, $profile->componentRanges, new IndirectReference($profile));
    }
}
