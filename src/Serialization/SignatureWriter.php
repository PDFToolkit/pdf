<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\FileStructure;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Syntax\FileStructure\FileRevision;

/** Allocate -> fix ByteRange -> digest -> fill Contents without moving any cross-reference offset (12.8.1). */
final readonly class SignatureWriter
{
    public function __construct(private FileWriter $writer = new FileWriter())
    {
    }

    public function prepare(FileStructure $file, PendingSignature $signature): PreparedSignature
    {
        return new PreparedSignature($this->writer->revision($file), $signature);
    }

    public function prepareRevision(FileRevision $revision, PendingSignature $signature): PreparedSignature
    {
        return new PreparedSignature($revision, $signature);
    }
}
