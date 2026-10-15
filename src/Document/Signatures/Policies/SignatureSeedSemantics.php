<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

/** The Adobe PDF 1.7 supplement and ISO PDF 2.0 define distinct seed contracts. */
enum SignatureSeedSemantics
{
    case Pdf17;
    case Pdf20;
}
