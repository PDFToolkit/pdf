<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

/** ISO table 221, PDF p.441. */
enum CommonFieldFlag: int
{
    case ReadOnly = 1;
    case Required = 2;
    case NoExport = 4;
}
