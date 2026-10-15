<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\Document\Signatures\VerifiedRevisionSignature;
use PDF\Syntax\FileStructure\FileRevision;

/** @internal Preserve a native graph and bytes while attaching its verified mathematical evidence. */
final readonly class VerifiedSignatureAttachment
{
    public function __construct(private VerifiedRevisionSignature $signature)
    {
    }

    public function emit(FileRevision $source, NativeRevisionEmission $emission): FileRevision
    {
        return $source->attachVerifiedSignature($this->signature, $emission);
    }
}
