<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

/** Traditional, binary or mixed classic/binary reference sections (ISO7.5.4/7.5.8.4). */
enum CrossReferenceFormat
{
    case Table;
    case Stream;
    case Hybrid;
}
