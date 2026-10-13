<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\FileStructure;
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
use PDF\Security\HistoricalRevision5Encryption;
use PDF\Security\DocumentMetadataExemption;
use PDF\Syntax\Object\StreamObject;

/** Stateful serialization belongs to a per-call context, separate from the file model. */
final class FileWriter
{
    public function __construct(
        private readonly CrossReferenceLimits $limits = new CrossReferenceLimits(),
        private readonly ?HistoricalRevision5Encryption $encryption = null,
    ) {
    }

    public function serialize(FileStructure $file): string
    {
        $result = $this->write($file);
        return $result instanceof FileRevision ? $result->bytes : $result;
    }

    public function revision(FileStructure $file): FileRevision
    {
        if ($this->encryption !== null) {
            throw new \LogicException('Encrypted revision snapshots and incremental writing are not implemented. Use serialize().');
        }
        $result = $this->write($file);
        return $result instanceof FileRevision ? $result : throw new \LogicException('An encrypted file cannot produce a revision snapshot.');
    }

    private function write(FileStructure $file): FileRevision|string
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
            $trailerEntries = ($trailerEntries ?? new DictionaryType())->with(new Entry(new NameType('Encrypt'), new IndirectReference($this->encryption->dictionary->dictionary())));
        }
        $security = $this->encryption === null ? [] : [$this->encryption];
        if ($this->encryption !== null && !$this->encryption->dictionary->encryptMetadata->value && $catalog->has('Metadata')) {
            $metadata = $catalog->get('Metadata');
            if (!$metadata instanceof IndirectReference || !$metadata->target() instanceof StreamObject) {
                throw new \LogicException('Catalog Metadata must reference the document-level metadata stream.');
            }
            $security[] = new DocumentMetadataExemption($metadata->target());
        }
        $context = new SerializationContext(new ObjectNumber(), ...$security);
        $context->prepareFile($file->body, $root, $trailerEntries);
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
                if ($this->encryption !== null && $member->value === $this->encryption->dictionary->dictionary()) {
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
        if ($compressed !== [] && $file->crossReferenceFormat !== CrossReferenceFormat::Stream) {
            throw new \LogicException('Object streams require a cross-reference stream.');
        }
        if ($file->crossReferenceFormat === CrossReferenceFormat::Stream && version_compare($file->header->version->value, '1.5', '<')) {
            throw new \LogicException('Cross-reference streams require PDF 1.5 or later.');
        }
        $text = (string) $file->header;
        $entries = array_values($compressed);
        $written = array_fill_keys(array_keys($compressed), true);
        do {
            $pending = array_diff_key($context->objects(), $written);
            foreach ($pending as $number => $object) {
                $identifier = $context->identifier($object);
                $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset(strlen($text)), $identifier->generationNumber);
                $text .= (new IndirectObject($object, $identifier))->toPdf($context);
                $written[$number] = true;
            }
        } while (array_diff_key($context->objects(), $written) !== []);
        $xrefOffset = strlen($text);
        $numbers = array_keys($context->objects());
        if ($numbers === []) {
            throw new \LogicException('The document must contain indirect objects.');
        }
        $maximumNumber = max($numbers);
        $reservedNumbers = $file->crossReferenceFormat === CrossReferenceFormat::Stream ? 2 : 1;
        if ($maximumNumber > PHP_INT_MAX - $reservedNumbers) {
            throw new \OverflowException('Not enough object number space to serialize this file.');
        }
        $size = $maximumNumber + 1;
        if ($file->crossReferenceFormat === CrossReferenceFormat::Stream) {
            $identifier = new ObjectIdentifier(new ObjectNumber($size));
            $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($xrefOffset));
            $section = new CrossReferenceSection(new IntegerType($size + 1), new IntegerType(1), new IntegerType(0), $this->limits, ...$entries);
            $xref = new CrossReferenceStream($section->size, $root, $trailerEntries, ...$section->entries());
            $context->register($xref, $identifier);
            $bytes = $text . (new IndirectObject($xref, $identifier))->toPdf($context)
                . "startxref\n" . $xrefOffset . "\n%%EOF\n";
            return $this->encryption === null ? $this->snapshot($file, $context, $bytes, $xrefOffset, $section, $trailerEntries) : $bytes;
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
        return $this->encryption === null ? $this->snapshot($file, $context, $bytes, $xrefOffset, $section, $trailerEntries) : $bytes;
    }

    private function snapshot(FileStructure $file, SerializationContext $context, string $bytes, int $offset, CrossReferenceSection $section, ?DictionaryType $trailerEntries): FileRevision
    {
        $objects = [];
        foreach ($context->objects() as $object) {
            $objects[] = new RevisionObject($context->identifier($object), $object);
        }
        $root = $file->root ?? $this->findRoot($file);
        return new FileRevision(
            $bytes,
            $file->header,
            new ByteOffset($offset),
            $section->size,
            new IndirectReference($root->target(), $context->identifier($root->target())),
            $trailerEntries ?? new DictionaryType(),
            $file->crossReferenceFormat,
            $section->freeListHead(),
            ...$objects,
        );
    }

    /** The encrypted API requires explicit ExtensionLevel 3 and two 16-byte file IDs. */
    private function validateEncryptionFile(FileStructure $file, DictionaryType $catalog): void
    {
        if ($file->header->version->value !== '1.7') {
            throw new \LogicException('Historical R=5 encryption requires a PDF 1.7 header and Adobe extension level 3.');
        }
        $extensions = $catalog->get('Extensions');
        $adobe = $extensions instanceof DictionaryType ? $extensions->get('ADBE') : null;
        $base = $adobe instanceof DictionaryType ? $adobe->get('BaseVersion') : null;
        $level = $adobe instanceof DictionaryType ? $adobe->get('ExtensionLevel') : null;
        if (!$base instanceof NameType || $base->value !== '1.7' || !$level instanceof IntegerType || $level->value < 3) {
            throw new \LogicException('Historical R=5 encryption requires explicit /Extensions /ADBE /BaseVersion /1.7 /ExtensionLevel 3 or greater.');
        }
        $id = $file->trailerEntries?->get('ID');
        if (!$id instanceof ArrayType || count($id->items) !== 2 || !$id->items[0] instanceof StringType || !$id->items[1] instanceof StringType
            || strlen($id->items[0]->value) !== 16 || strlen($id->items[1]->value) !== 16) {
            throw new \LogicException('The encrypted writer requires an explicit trailer ID with two direct 16-byte strings.');
        }
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
