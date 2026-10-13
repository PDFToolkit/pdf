<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

/** Table 254 permission levels; actual revision classification is a separate writer operation. */
enum DocMdpPermissions: int
{
    case NoChanges = 1;
    case FormFillingTemplatesAndSigning = 2;
    case FormsTemplatesSigningAndAnnotations = 3;
}
