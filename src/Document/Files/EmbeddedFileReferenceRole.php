<?php

declare(strict_types=1);

namespace PDF\Document\Files;

/** The reference position matters: naming a private key EF does not make it a file specification. */
enum EmbeddedFileReferenceRole
{
    case Ordinary;
    case EmbeddedBindings;
    case RelatedBindings;
    case RelatedPairs;
    case FileStream;
}
