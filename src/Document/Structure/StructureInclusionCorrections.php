<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Explicitly selects the supplied 2023 text or the two published PDF Association resolutions. */
enum StructureInclusionCorrections
{
    case Published2023;
    /** PDF Association issues 336 (Code/Sub) and 440 (WP/Figure), not a new ISO edition. */
    case Resolved336And440;
}
