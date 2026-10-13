<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\FileStructure;
use PDF\Document\DocumentGraphValidation;
use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceEntry;
use PDF\Syntax\FileStructure\CrossReferenceTable;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\CrossReferenceStream;
use PDF\Syntax\FileStructure\CrossReferenceStreamFreeEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamInUseEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamCompressedEntry;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\FileTrailer;
use PDF\Syntax\FileStructure\CrossReferenceLimits;
use PDF\Syntax\FileStructure\CrossReferenceSection;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\ObjectByteSpan;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\FileStructure\HybridReferenceObjects;
use PDF\Syntax\FileStructure\HybridCrossReferenceSection;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Security\FileEncryption;
use PDF\Security\FileIdentifier;
use PDF\Security\DocumentMetadataExemption;
use PDF\Syntax\Object\StreamObject;

/** Stateful serialization belongs to a per-call context, separate from the file model. */
final class FileWriter
{
    public function __construct(
        private readonly CrossReferenceLimits $limits = new CrossReferenceLimits(),
        private readonly ?FileEncryption $encryption = null,
    ) {
    }

    public function serialize(FileStructure $file): string
    {
        return $this->write($file)->bytes;
    }

    public function revision(FileStructure $file): FileRevision
    {
        return $this->write($file);
    }

    private function write(FileStructure $file): FileRevision
    {
        $root = $file->root ?? $this->findRoot($file);
        $catalog = $root->target();
        if (!$catalog instanceof DictionaryType || !($catalog->get('Type') instanceof NameType) || $catalog->get('Type')->value !== 'Catalog') {
            throw new \LogicException('The trailer Root must refer to a document catalog.');
        }
        if (!$catalog->get('Pages') instanceof IndirectReference) {
            throw new \LogicException('The catalog must contain an indirect Pages entry.');
        }
        foreach (['Size', 'Root', 'Prev', 'XRefStm', 'Encrypt'] as $managed) {
            if ($file->trailerEntries?->has($managed)) {
                throw new \LogicException('Unsupported or managed trailer entry: ' . $managed);
            }
        }
        $trailerEntries = $file->trailerEntries;
        if ($this->encryption !== null) {
            $this->validateEncryptionFile($file, $catalog);
            $trailerEntries = ($trailerEntries ?? new DictionaryType())->with(new Entry(new NameType('Encrypt'), new IndirectReference($this->encryption->dictionary())));
        }
        $security = $this->encryption === null ? [] : [$this->encryption];
        if ($this->encryption !== null && !$this->encryption->metadata()->value && $catalog->has('Metadata')) {
            $metadata = $catalog->get('Metadata');
            if (!$metadata instanceof IndirectReference || !$metadata->target() instanceof StreamObject) {
                throw new \LogicException('Catalog Metadata must reference the document-level metadata stream.');
            }
            $security[] = new DocumentMetadataExemption($metadata->target());
        }
        $context = new SerializationContext(new ObjectNumber(), ...$security);
        $context->prepareFile($file->body, $root, $trailerEntries);
        (new DocumentGraphValidation($context, $catalog))->validate($file->header);
        foreach ($context->objects() as $object) {
            if ($context->identifier($object)->generationNumber->value !== 0) {
                throw new \LogicException('Objects in an initial file start with generation zero.');
            }
        }
        $compressed = [];
        foreach ($context->objects() as $object) {
            if (!$object instanceof ObjectStream) {
                continue;
            }
            if ($context->identifier($object)->generationNumber->value !== 0) {
                throw new \LogicException('An object stream must have generation zero.');
            }
            foreach ($object->embeddedEntries() as $index => $member) {
                if ($this->encryption !== null && $member->value === $this->encryption->dictionary()) {
                    throw new \LogicException('An encryption dictionary cannot be embedded in an object stream.');
                }
                if ($this->encryption !== null && $member->value instanceof DictionaryType && $context->isByteRangeSignature($member->value)) {
                    throw new \LogicException('A byte-range signature container cannot be embedded in an encrypted object stream.');
                }
                $number = $member->objectNumber->value;
                if (isset($compressed[$number])) {
                    throw new \LogicException('An object cannot belong to multiple object streams.');
                }
                $compressed[$number] = new CrossReferenceStreamCompressedEntry(
                    $member->objectNumber,
                    $context->identifier($object)->objectNumber,
                    new IntegerType($index),
                );
            }
        }
        $hybrid = $file->crossReferenceFormat === CrossReferenceFormat::Hybrid ? new HybridReferenceObjects($context, $catalog) : null;
        $compressed = $hybrid?->compressedEntries() ?? $compressed;
        if ($compressed !== [] && $file->crossReferenceFormat === CrossReferenceFormat::Table) {
            throw new \LogicException('Object streams require a cross-reference stream.');
        }
        if ($file->crossReferenceFormat !== CrossReferenceFormat::Table) {
            (new EffectivePdfVersion($file->header, $catalog))->require(PdfVersion::PDF15, 'Cross-reference streams');
        }
        $text = (string) $file->header;
        $spans = [];
        $entries = array_values($compressed);
        $written = array_fill_keys(array_keys($compressed), true);
        do {
            $pending = array_diff_key($context->objects(), $written);
            foreach ($pending as $number => $object) {
                $identifier = $context->identifier($object);
                $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset(strlen($text)), $identifier->generationNumber);
                $value = $hybrid?->serializedValue($object) ?? $object;
                $objectBytes = $identifier . " obj\n" . $context->serializeIndirect($value, $identifier) . "\nendobj\n";
                $spans[] = new ObjectByteSpan($identifier, new ByteOffset(strlen($text)), strlen($objectBytes));
                $text .= $objectBytes;
                $written[$number] = true;
            }
        } while (array_diff_key($context->objects(), $written) !== []);
        $xrefOffset = strlen($text);
        $numbers = array_keys($context->objects());
        if ($numbers === []) {
            throw new \LogicException('The document must contain indirect objects.');
        }
        $maximumNumber = max($numbers);
        $reservedNumbers = $file->crossReferenceFormat === CrossReferenceFormat::Table ? 1 : 2;
        if ($maximumNumber > PHP_INT_MAX - $reservedNumbers) {
            throw new \OverflowException('Not enough object number space to serialize this file.');
        }
        $size = $maximumNumber + 1;
        if ($hybrid !== null) {
            $identifier = new ObjectIdentifier(new ObjectNumber($size));
            $fullSize = new IntegerType($size + 1);
            $placeholder = new CrossReferenceSection(
                $fullSize,
                new IntegerType(1),
                new IntegerType(0),
                $this->limits,
                ...[...$entries, new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset(0))]
            );
            $fallback = (new HybridCrossReferenceSection($placeholder, $identifier->objectNumber))->fallback();
            $mainTrailer = new FileTrailer($fullSize, $root, ...($trailerEntries === null ? [] : iterator_to_array($trailerEntries->entries(), false)));
            $text .= $fallback . "trailer\n" . $context->serialize($mainTrailer) . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
            $auxiliaryOffset = strlen($text);
            $section = new CrossReferenceSection(
                $fullSize,
                new IntegerType(1),
                new IntegerType(0),
                $this->limits,
                ...[...$entries, new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($auxiliaryOffset))]
            );
            $xref = new CrossReferenceStream($fullSize, $root, $trailerEntries, ...$section->entries());
            $context->register($xref, $identifier);
            $xrefBytes = (new IndirectObject($xref, $identifier))->toPdf($context);
            $spans[] = new ObjectByteSpan($identifier, new ByteOffset($auxiliaryOffset), strlen($xrefBytes));
            $text .= $xrefBytes;
            $updateOffset = strlen($text);
            $extras = ($trailerEntries ?? new DictionaryType())->with(new Entry(new NameType('Prev'), new IntegerType($xrefOffset)))
                ->with(new Entry(new NameType('XRefStm'), new IntegerType($auxiliaryOffset)));
            $updateTrailer = new FileTrailer($fullSize, $root, ...iterator_to_array($extras->entries(), false));
            $bytes = $text . (new HybridCrossReferenceSection($section, $identifier->objectNumber))->update()
                . "trailer\n" . $context->serialize($updateTrailer) . "\nstartxref\n" . $updateOffset . "\n%%EOF\n";
            return $this->snapshot($file, $context, $bytes, $updateOffset, $section, $trailerEntries, $spans, $hybrid);
        }
        if ($file->crossReferenceFormat === CrossReferenceFormat::Stream) {
            $identifier = new ObjectIdentifier(new ObjectNumber($size));
            $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($xrefOffset));
            $section = new CrossReferenceSection(new IntegerType($size + 1), new IntegerType(1), new IntegerType(0), $this->limits, ...$entries);
            $xref = new CrossReferenceStream($section->size, $root, $trailerEntries, ...$section->entries());
            $context->register($xref, $identifier);
            $xrefBytes = (new IndirectObject($xref, $identifier))->toPdf($context);
            $spans[] = new ObjectByteSpan($identifier, new ByteOffset(strlen($text)), strlen($xrefBytes));
            $bytes = $text . $xrefBytes
                . "startxref\n" . $xrefOffset . "\n%%EOF\n";
            return $this->snapshot($file, $context, $bytes, $xrefOffset, $section, $trailerEntries, $spans);
        }
        $section = new CrossReferenceSection(new IntegerType($size), new IntegerType(1), new IntegerType(0), $this->limits, ...$entries);
        $traditional = [];
        foreach ($section->entries() as $entry) {
            if ($entry instanceof CrossReferenceStreamInUseEntry) {
                $traditional[] = new CrossReferenceEntry($entry->number(), $entry->byteOffset, $entry->generation, true);
            } elseif ($entry instanceof CrossReferenceStreamFreeEntry) {
                $traditional[] = new CrossReferenceEntry($entry->number(), new ByteOffset($entry->fields()[1]), $entry->generation, false);
            }
        }
        $trailer = new FileTrailer(new IntegerType($size), $root, ...($trailerEntries === null ? [] : iterator_to_array($trailerEntries->entries(), false)));
        $bytes = $text . new CrossReferenceTable(...$traditional) . "trailer\n" . $context->serialize($trailer)
            . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
        return $this->snapshot($file, $context, $bytes, $xrefOffset, $section, $trailerEntries, $spans);
    }

    /** @param list<ObjectByteSpan> $spans */
    private function snapshot(FileStructure $file, SerializationContext $context, string $bytes, int $offset, CrossReferenceSection $section, ?DictionaryType $trailerEntries, array $spans, ?HybridReferenceObjects $hybrid = null): FileRevision
    {
        $trailerSnapshot = $context->serializedSnapshot($trailerEntries ?? new DictionaryType())->value;
        if (!$trailerSnapshot instanceof DictionaryType) {
            throw new \LogicException('A revision trailer freezes as a dictionary.');
        }
        $objects = [];
        foreach ($context->objects() as $object) {
            $identifier = $context->identifier($object);
            $value = $hybrid?->serializedValue($object) ?? $object;
            $objects[] = new RevisionObject($identifier, $value, $object, $context->serializedSnapshot($value, $identifier));
        }
        $root = $file->root ?? $this->findRoot($file);
        return new FileRevision(
            $bytes,
            $file->header,
            new ByteOffset($offset),
            $section->size,
            new IndirectReference($root->target(), $context->identifier($root->target())),
            $trailerSnapshot,
            $file->crossReferenceFormat,
            $section->freeListHead(),
            ...[...$objects, ...$spans, ...$context->references(), ...($this->encryption === null ? [] : [$this->encryption])],
        );
    }

    /** File identifiers and trailer plaintext boundaries are shared by the security handlers. */
    private function validateEncryptionFile(FileStructure $file, DictionaryType $catalog): void
    {
        $id = $file->trailerEntries?->get('ID');
        if (!$id instanceof ArrayType || count($id->items) !== 2 || !$id->items[0] instanceof StringType || !$id->items[1] instanceof StringType
            || strlen($id->items[0]->value) !== 16 || strlen($id->items[1]->value) !== 16) {
            throw new \LogicException('The encrypted writer requires an explicit trailer ID with two direct 16-byte strings.');
        }
        $this->encryption?->validateFile($file->header, $catalog, new FileIdentifier($id->items[0]));
        // Trailer dictionaries and cross-reference stream dictionaries stay clear.
        // Arbitrary strings must therefore live in an encrypted indirect object.
        $pending = [];
        foreach ($file->trailerEntries->entries() as $entry) {
            if ($entry->key->value !== 'ID') {
                $pending[] = $entry->value;
            }
        }
        $seen = new \SplObjectStorage();
        while ($pending !== []) {
            $value = array_pop($pending);
            if ($value instanceof IndirectReference || $seen->offsetExists($value)) {
                continue;
            }
            $seen->offsetSet($value);
            if ($value instanceof StringType) {
                throw new \LogicException('Direct trailer strings outside ID remain clear; supply an indirect reference instead.');
            }
            if ($value instanceof ArrayType) {
                foreach ($value->items as $item) {
                    $pending[] = $item;
                }
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $pending[] = $entry->value;
                }
            }
        }
    }

    private function findRoot(FileStructure $file): IndirectReference
    {
        $catalogs = [];
        foreach ($file->body->objects() as $object) {
            $value = $object instanceof IndirectObject ? $object->value : $object;
            if ($value instanceof DictionaryType && $value->get('Type') instanceof NameType && $value->get('Type')->value === 'Catalog') {
                $catalogs[] = new IndirectReference($value, $object instanceof IndirectObject ? $object->identifier : null);
            }
        }
        if (count($catalogs) !== 1) {
            throw new \LogicException('Provide a root or exactly one catalog in the file body.');
        }
        return $catalogs[0];
    }

}
