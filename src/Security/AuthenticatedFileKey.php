<?php

declare(strict_types=1);

namespace PDF\Security;

/** The authenticated key and access class, after checking the encrypted Perms block. */
final readonly class AuthenticatedFileKey
{
    public function __construct(public FileEncryptionKey $key, public PasswordAccess $access)
    {
    }
}
