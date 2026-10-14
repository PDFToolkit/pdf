<?php

declare(strict_types=1);

namespace PDF\Document\Files;

/** Semantic owner context is obtained from actual PDF relationships, not guessed from private keys. */
enum AssociatedFileOwner
{
    case Document;
    case Page;
    case StructureElement;
    case FormXObject;
    case ImageXObject;
    case DocumentPart;
    case Annotation;
    case Metadata;
}
