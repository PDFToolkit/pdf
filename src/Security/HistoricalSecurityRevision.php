<?php

declare(strict_types=1);

namespace PDF\Security;

/** Legacy Standard Security Handler revisions covered by supplement Algorithm 3.2. */
enum HistoricalSecurityRevision: int
{
    case Revision2 = 2;
    case Revision3 = 3;
    case Revision4 = 4;
}
