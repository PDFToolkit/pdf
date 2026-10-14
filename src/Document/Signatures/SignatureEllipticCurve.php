<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\SignatureDigestMethod as Digest;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;

/** ISO/TS 32002:2022 Tables 3 and 4, physical PDF page 11. */
enum SignatureEllipticCurve: string
{
    case P256 = 'P-256';
    case P384 = 'P-384';
    case P521 = 'P-521';
    case BrainpoolP256r1 = 'brainpoolP256r1';
    case BrainpoolP384r1 = 'brainpoolP384r1';
    case BrainpoolP512r1 = 'brainpoolP512r1';
    case Ed25519 = 'Ed25519';
    case Ed448 = 'Ed448';

    /** @return non-empty-list<Digest> */
    public function digests(): array
    {
        return match ($this) {
            self::P256 => [Digest::SHA256, Digest::SHA3_256],
            self::P384 => [Digest::SHA384, Digest::SHA3_384],
            self::P521 => [Digest::SHA512, Digest::SHA3_512],
            self::BrainpoolP256r1 => [Digest::SHA256, Digest::SHA384, Digest::SHA512, Digest::SHA3_256, Digest::SHA3_384, Digest::SHA3_512],
            self::BrainpoolP384r1 => [Digest::SHA384, Digest::SHA512, Digest::SHA3_384, Digest::SHA3_512],
            self::BrainpoolP512r1 => [Digest::SHA512, Digest::SHA3_512],
            self::Ed25519 => [Digest::SHA512], self::Ed448 => [Digest::SHAKE256],
        };
    }

    public function validate(Digest $digest): void
    {
        if (!in_array($digest, $this->digests(), true)) {
            throw new \InvalidArgumentException('The signature curve and digest combination is not specified by ISO/TS 32002.');
        }
    }

    public function keyAlgorithm(): SignatureKeyAlgorithm
    {
        return in_array($this, [self::Ed25519, self::Ed448], true) ? SignatureKeyAlgorithm::EdDSA : SignatureKeyAlgorithm::ECDSA;
    }

    public function oid(): string
    {
        return match ($this) {
            self::P256 => '1.2.840.10045.3.1.7', self::P384 => '1.3.132.0.34', self::P521 => '1.3.132.0.35',
            self::BrainpoolP256r1 => '1.3.36.3.3.2.8.1.1.7', self::BrainpoolP384r1 => '1.3.36.3.3.2.8.1.1.11',
            self::BrainpoolP512r1 => '1.3.36.3.3.2.8.1.1.13', self::Ed25519 => '1.3.101.112', self::Ed448 => '1.3.101.113',
        };
    }
}
