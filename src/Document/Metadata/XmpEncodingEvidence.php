<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** Immutable source-encoding evidence bound to the exact emitted encoded plaintext. */
final readonly class XmpEncodingEvidence
{
    private string $encodedFingerprint;
    private bool $utf8;

    public function __construct(string $sourceBytes, string $encodedBytes)
    {
        $this->encodedFingerprint = hash('sha256', $encodedBytes);
        $this->utf8 = (new XmpUtf8Encoding())->accepts($sourceBytes);
    }

    public function validate(string $encodedBytes): void
    {
        if (!$this->utf8 || !hash_equals($this->encodedFingerprint, hash('sha256', $encodedBytes))) {
            throw new \InvalidArgumentException('Retained PDF2 XMP metadata lacks matching UTF-8 emission evidence (EC3 #296).');
        }
    }
}
