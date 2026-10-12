<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention;

/** End-of-line markers are distinct from the %%EOF end-of-file marker (7.2.2). */
enum EndOfLineKind: string
{
    case CarriageReturn = "\r";
    case LineFeed = "\n";
    case CarriageReturnLineFeed = "\r\n";
}
