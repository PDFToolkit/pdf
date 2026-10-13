<?php

declare(strict_types=1);

namespace PDF\Document\Files;

enum FilePathKind
{
    case Absolute;
    case Relative;
}
