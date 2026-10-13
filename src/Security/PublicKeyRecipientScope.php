<?php

declare(strict_types=1);

namespace PDF\Security;

/** Table27: document defaults include permissions; individual Crypt streams have one seed-only envelope. */
enum PublicKeyRecipientScope
{
    case Document;
    case IndividualStream;
}
