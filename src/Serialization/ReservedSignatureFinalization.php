<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\RevisionObject;

/** @internal Exact reserved-byte completion is a concrete native emission operation. */
final readonly class ReservedSignatureFinalization
{
    public function __construct(private string $bytes, private RevisionObject $replacement)
    {
    }

    public function emit(FileRevision $source, NativeRevisionEmission $emission): FileRevision
    {
        return $source->finalizeReserved($this->bytes, $this->replacement, $emission);
    }
}
