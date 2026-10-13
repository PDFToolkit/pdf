<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\FileRevision;

/** Frozen signed revision; trust, revocation and timestamp policies are explicit external handler decisions. */
final readonly class SignedRevision implements \Stringable
{
    public function __construct(
        public FileRevision $revision,
        public SignatureByteRange $range,
        public SignatureValue $value,
        public SignatureFormat $format
    ) {
    }

    public function writeTo(string $path): void
    {
        $this->revision->writeTo($path);
    }

    public function __toString(): string
    {
        return $this->revision->bytes;
    }
}
