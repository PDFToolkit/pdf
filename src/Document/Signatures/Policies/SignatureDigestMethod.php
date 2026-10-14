<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

/** Table234 RSA signing digests. DSA is constrained separately to SHA1. */
enum SignatureDigestMethod: string
{
    case SHA1 = 'SHA1';
    case SHA256 = 'SHA256';
    case SHA384 = 'SHA384';
    case SHA512 = 'SHA512';
    case RIPEMD160 = 'RIPEMD160';
    case SHA3_256 = 'SHA3-256';
    case SHA3_384 = 'SHA3-384';
    case SHA3_512 = 'SHA3-512';
    case SHAKE256 = 'SHAKE256';

    public function hashName(): string
    {
        return match ($this) {
            self::SHA1 => 'sha1', self::SHA256 => 'sha256', self::SHA384 => 'sha384', self::SHA512 => 'sha512',
            self::RIPEMD160 => 'ripemd160', self::SHA3_256 => 'sha3-256', self::SHA3_384 => 'sha3-384', self::SHA3_512 => 'sha3-512',
            self::SHAKE256 => 'shake256',
        };
    }

    public function digest(string $bytes): string
    {
        return $this === self::SHAKE256 ? (new \PDF\Security\Shake256())->digest($bytes) : hash($this->hashName(), $bytes, true);
    }

    public function oid(): string
    {
        return match ($this) {
            self::SHA1 => '1.3.14.3.2.26', self::SHA256 => '2.16.840.1.101.3.4.2.1', self::SHA384 => '2.16.840.1.101.3.4.2.2',
            self::SHA512 => '2.16.840.1.101.3.4.2.3', self::RIPEMD160 => '1.3.36.3.2.1',
            self::SHA3_256 => '2.16.840.1.101.3.4.2.8', self::SHA3_384 => '2.16.840.1.101.3.4.2.9',
            self::SHA3_512 => '2.16.840.1.101.3.4.2.10', self::SHAKE256 => '2.16.840.1.101.3.4.2.12',
        };
    }
}
