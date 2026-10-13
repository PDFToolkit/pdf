<?php

declare(strict_types=1);

namespace PDF\Security;

/** The CBC and ECB operations required by supplement Algorithms 3.1a, 3.2a and 3.10. */
interface Aes256Backend
{
    public function encryptCbc(string $bytes, FileEncryptionKey $key, InitializationVector $iv, bool $padding): string;

    public function decryptCbc(string $bytes, FileEncryptionKey $key, InitializationVector $iv, bool $padding): string;

    public function encryptEcb(string $bytes, FileEncryptionKey $key): string;

    public function decryptEcb(string $bytes, FileEncryptionKey $key): string;
}
