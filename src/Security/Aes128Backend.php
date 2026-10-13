<?php

declare(strict_types=1);

namespace PDF\Security;

interface Aes128Backend
{
    public function encryptCbc(string $bytes, HistoricalEncryptionKey $key, InitializationVector $iv, bool $padding): string;
    public function decryptCbc(string $bytes, HistoricalEncryptionKey $key, InitializationVector $iv, bool $padding): string;
}
