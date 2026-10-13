<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

/** ISO 7.4.4.2, physical PDF pages 34–35. */
enum LzwControlCode: int
{
    case ClearTable = 256;
    case EndOfData = 257;
}
