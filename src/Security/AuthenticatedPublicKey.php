<?php

declare(strict_types=1);

namespace PDF\Security;

/** First matching recipient envelope's key and permissions (7.6.4.2). */
final readonly class AuthenticatedPublicKey
{
    public function __construct(
        public HistoricalEncryptionKey|FileEncryptionKey $key,
        public ?PublicKeyPermissions $permissions,
        public int $recipientIndex
    ) {
    }
}
