<?php

declare(strict_types=1);

namespace PDF\Security;

/** The authenticated key and access class, independently of any file reader. */
final readonly class AuthenticatedHistoricalFileKey
{
    public function __construct(public HistoricalEncryptionKey $key, public PasswordAccess $access)
    {
    }
}
