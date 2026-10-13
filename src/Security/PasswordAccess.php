<?php

declare(strict_types=1);

namespace PDF\Security;

/** Password authentication records whether user or owner permissions apply. */
enum PasswordAccess
{
    case User;
    case Owner;
}
