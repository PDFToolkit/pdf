<?php

declare(strict_types=1);

namespace PDF\Specification;

/** ISO 32000-2:2020, clauses 3.15 and 6: deprecation is not prohibition. */
enum FeatureStatus
{
    case Available;
    case NotIntroduced;
    case Deprecated;
    case Prohibited;
}
