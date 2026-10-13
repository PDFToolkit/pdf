<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** Explicit normalized table declaration supplied by the external font processor (Table 126). */
enum OpenTypeOutline
{
    case Glyf;
    case CffSimple;
    case CffCid;
}
