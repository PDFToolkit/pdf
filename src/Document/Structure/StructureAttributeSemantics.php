<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Placement defaults are edition-dependent; PDF2 uses the actual structure context. */
enum StructureAttributeSemantics
{
    case Pdf17;
    case Pdf20;
}
