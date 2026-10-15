<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\Document\Signatures\VerifiedRevisionSignature;
use PDF\FileStructure;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\RevisionObject;

/**
 * One document-scoped emission capability, bound only after the native operation
 * has produced its frozen graph and bytes. A manually constructed FileRevision
 * is not an emitted revision, even if its byte prefix resembles one.
 *
 * @internal No global registry, parser attestation or PKI trust is implied.
 */
final readonly class NativeRevisionEmission
{
    public FileRevision $revision;

    public function __construct(FileWriter|LinearizedWriter|IncrementalWriter|ReservedSignatureFinalization|VerifiedSignatureAttachment $operation, FileStructure|IncrementalUpdate|FileRevision $source)
    {
        $this->revision = match (true) {
            ($operation instanceof FileWriter || $operation instanceof LinearizedWriter) && $source instanceof FileStructure => $operation->emit($source, $this),
            $operation instanceof IncrementalWriter && $source instanceof IncrementalUpdate => $operation->emit($source, $this),
            ($operation instanceof ReservedSignatureFinalization || $operation instanceof VerifiedSignatureAttachment) && $source instanceof FileRevision => $operation->emit($source, $this),
            default => throw new \InvalidArgumentException('A native emission executes its actual concrete operation on the corresponding source model.'),
        };
    }

    public function owns(FileRevision $revision): bool
    {
        return isset($this->revision) && $this->revision === $revision;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Native emission capabilities cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Native emission capabilities cannot be restored from serialized caller data.');
    }

}
