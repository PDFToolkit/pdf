<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceEntry;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\CrossReferenceLimits;
use PDF\Syntax\FileStructure\CrossReferenceSection;
use PDF\Syntax\FileStructure\CrossReferenceStream;
use PDF\Syntax\FileStructure\CrossReferenceStreamCompressedEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamFreeEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamInUseEntry;
use PDF\Syntax\FileStructure\CrossReferenceTable;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\FileTrailer;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\ObjectByteSpan;
use PDF\Syntax\FileStructure\HybridReferenceObjects;
use PDF\Syntax\FileStructure\HybridCrossReferenceSection;
use PDF\Security\DocumentMetadataExemption;
use PDF\Security\FileIdentifier;
use PDF\Syntax\Object\StreamObject;
use PDF\Document\DocumentGraphValidation;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Append a delta while preserving every byte and reference identity of previous revisions (7.5.6). */
final class IncrementalWriter
{
    public function __construct(private readonly CrossReferenceLimits $limits = new CrossReferenceLimits())
    {
    }

    public function serialize(IncrementalUpdate $update): FileRevision
    {
        $previous = $update->previous;
        $security = $previous->encryption === null ? [] : [$previous->encryption];
        $context = new SerializationContext(new ObjectNumber($previous->size->integer()), ...[...$previous->objects(), ...$security]);
        $effective = [];
        foreach ($previous->objects() as $record) {
            $effective[$record->identifier->objectNumber->value] = $record;
        }
        foreach ($update->body->objects() as $object) {
            if (!$object instanceof IndirectObject && $context->isHistorical($object)) {
                $object = new IndirectObject($object, $context->identifier($object));
            }
            if ($object instanceof IndirectObject) {
                $old = $effective[$object->identifier->objectNumber->value] ?? null;
                if ($old !== null && ($old->value instanceof CrossReferenceStream || $old->value instanceof ObjectStream)) {
                    throw new \LogicException('Existing structural streams cannot be replaced; create a new object-stream container.');
                }
                if ($old !== null && $old->value === $previous->encryption?->dictionary()) {
                    throw new \LogicException('Incremental revisions must preserve the encryption dictionary and file key.');
                }
                $context->stage($object);
            }
        }
        $trailer = $previous->trailerEntries;
        foreach ($update->trailerEntries?->entries() ?? [] as $entry) {
            $trailer = $trailer->with($entry);
        }
        $root = $update->root ?? $previous->root;
        // Validate inherited and overridden trailer values before serializing any object.
        new FileTrailer($previous->size, $root, ...iterator_to_array($trailer->entries(), false));
        $this->validateIdentifierChange($previous, $update);
        $context->prepareFile($update->body, $root, $trailer);
        foreach ($context->objects() as $number => $object) {
            $old = $effective[$number] ?? null;
            if ($old !== null && $old->value === $previous->encryption?->dictionary()) {
                throw new \LogicException('Incremental revisions must preserve the encryption dictionary and file key, including object-stream members.');
            }
            if ($old !== null && ($old->value instanceof ObjectStream || $old->value instanceof CrossReferenceStream)) {
                throw new \LogicException('Existing structural streams cannot be replaced through object-stream members.');
            }
            if ($old === null && $context->identifier($object)->generationNumber->value !== 0) {
                throw new \LogicException('New objects start with generation zero.');
            }
            $effective[$number] = $old === null ? new RevisionObject($context->identifier($object), $object) : $old->replacedBy($object);
        }
        $rootIdentifier = $context->reference($root);
        $catalogRecord = $effective[$rootIdentifier->objectNumber->value] ?? null;
        $catalog = $catalogRecord?->value;
        $catalogDictionary = $catalogRecord?->dictionary;
        if (!$catalog instanceof DictionaryType || !($catalogDictionary?->get('Type') instanceof NameType)
            || $catalogDictionary->get('Type')->value !== 'Catalog' || !$catalogDictionary->get('Pages') instanceof IndirectReference) {
            throw new \LogicException('The effective incremental Root must be a catalog with an indirect Pages entry.');
        }
        // Current typed components need their explicit construction identities; unchanged roots use frozen values.
        $validationCatalog = ($context->objects()[$rootIdentifier->objectNumber->value] ?? null) === $catalog ? $catalog : $catalogDictionary;
        (new DocumentGraphValidation($context, $validationCatalog, ...array_values($effective)))->validate($previous->header);
        if ($previous->encryption !== null) {
            if (!$previous->encryption->metadata()->value && $catalogDictionary->has('Metadata')) {
                $metadata = $catalogDictionary->get('Metadata');
                if (!$metadata instanceof IndirectReference) {
                    throw new \LogicException('Catalog Metadata must reference the document-level metadata stream.');
                }
                $metadataIdentifier = $context->reference($metadata);
                $metadataStream = ($effective[$metadataIdentifier->objectNumber->value] ?? null)?->value;
                if (!$metadataStream instanceof StreamObject) {
                    throw new \LogicException('The effective Catalog Metadata must be a stream.');
                }
                $context->excludeDocumentMetadata(new DocumentMetadataExemption($metadataStream));
            }
            $id = $trailer->get('ID');
            if (!$id instanceof ArrayType || !($id->items[0] ?? null) instanceof StringType) {
                throw new \LogicException('An encrypted incremental file retains its original file identifier.');
            }
            $previous->encryption->validateFile($previous->header, $catalogDictionary, new FileIdentifier($id->items[0]));
            $this->validateEncryptedTrailer($trailer);
        }
        $pagesReference = $catalogDictionary->get('Pages');
        if (!$pagesReference instanceof IndirectReference) {
            throw new \LogicException('The effective Catalog.Pages must remain an indirect reference.');
        }
        $pagesIdentifier = $context->reference($pagesReference);
        $pagesDictionary = ($effective[$pagesIdentifier->objectNumber->value] ?? null)?->dictionary;
        if (!$pagesDictionary?->get('Type') instanceof NameType || $pagesDictionary->get('Type')->value !== 'Pages' || $pagesDictionary->has('Parent')) {
            throw new \LogicException('The effective Pages reference must remain a root /Pages dictionary.');
        }
        $hybrid = $previous->crossReferenceFormat === CrossReferenceFormat::Hybrid ? new HybridReferenceObjects($context, $catalogDictionary, $previous) : null;
        $compressed = [];
        foreach ($context->objects() as $object) {
            if (!$object instanceof ObjectStream) {
                continue;
            }
            if ($previous->crossReferenceFormat === CrossReferenceFormat::Table) {
                throw new \LogicException('Object streams require the inherited cross-reference-stream format.');
            }
            $container = $context->identifier($object);
            if ($container->generationNumber->value !== 0) {
                throw new \LogicException('An object stream must have generation zero.');
            }
            foreach ($object->embeddedEntries() as $index => $member) {
                if ($previous->encryption !== null && ($member->value === $previous->encryption->dictionary()
                    || ($member->value instanceof DictionaryType && $context->isByteRangeSignature($member->value)))) {
                    throw new \LogicException('Encryption and byte-range signature dictionaries cannot be in encrypted object streams.');
                }
                $number = $member->objectNumber->value;
                if (isset($compressed[$number])) {
                    throw new \LogicException('An object cannot belong to multiple object streams.');
                }
                $compressed[$number] = new CrossReferenceStreamCompressedEntry(
                    $member->objectNumber,
                    $container->objectNumber,
                    new IntegerType($index),
                );
            }
        }
        $compressed = $hybrid?->compressedEntries() ?? $compressed;
        $bytes = $previous->bytes;
        $spans = [];
        foreach ($previous->spans() as $span) {
            $spans[$span->identifier->objectNumber->value] = $span;
        }
        foreach (array_keys($compressed) as $number) {
            unset($spans[$number]);
        }
        $entries = array_values($compressed);
        $written = array_fill_keys(array_keys($compressed), true);
        do {
            foreach (array_diff_key($context->objects(), $written) as $number => $object) {
                if ($object instanceof CrossReferenceStream) {
                    throw new \LogicException('Cross-reference streams belong to the incremental writer.');
                }
                $identifier = $context->identifier($object);
                $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset(strlen($bytes)), $identifier->generationNumber);
                $value = $hybrid?->serializedValue($object) ?? $object;
                $objectBytes = $identifier . " obj\n" . $context->serializeIndirect($value, $identifier) . "\nendobj\n";
                $spans[$number] = new ObjectByteSpan($identifier, new ByteOffset(strlen($bytes)), strlen($objectBytes));
                $bytes .= $objectBytes;
                $written[$number] = true;
            }
        } while (array_diff_key($context->objects(), $written) !== []);
        $offset = strlen($bytes);
        $maximum = max([$previous->size->integer() - 1, ...array_keys($context->objects())]);
        $reserve = $previous->crossReferenceFormat === CrossReferenceFormat::Table ? 1 : 2;
        if ($maximum > PHP_INT_MAX - $reserve) {
            throw new \OverflowException('Not enough object number space for an incremental revision.');
        }
        $firstAvailable = (new ObjectNumber($maximum))->increment();
        $size = $firstAvailable->value;
        $trailer = $this->updatedIdentifier($previous, $update, $trailer, $bytes, $context);
        $extras = $trailer->with(new Entry(new NameType('Prev'), new IntegerType($previous->crossReferenceOffset->value)));
        $root = new IndirectReference($catalog, $rootIdentifier);
        if ($hybrid !== null) {
            $identifier = new ObjectIdentifier($firstAvailable);
            $fullSize = new IntegerType($firstAvailable->increment()->value);
            $placeholder = new CrossReferenceSection(
                $fullSize,
                $previous->size,
                $previous->freeListHead,
                $this->limits,
                ...[...$entries, new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset(0))]
            );
            $fallback = (new HybridCrossReferenceSection($placeholder, $identifier->objectNumber))->fallback();
            $fallbackTrailer = new FileTrailer($fullSize, $root, ...iterator_to_array($extras->entries(), false));
            $bytes .= $fallback . "trailer\n" . $context->serialize($fallbackTrailer) . "\nstartxref\n" . $offset . "\n%%EOF\n";
            $auxiliaryOffset = strlen($bytes);
            $section = new CrossReferenceSection(
                $fullSize,
                $previous->size,
                $previous->freeListHead,
                $this->limits,
                ...[...$entries, new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($auxiliaryOffset))]
            );
            $xref = new CrossReferenceStream($fullSize, $root, $trailer, ...$section->entries());
            $context->register($xref, $identifier);
            $xrefBytes = (new IndirectObject($xref, $identifier))->toPdf($context);
            $spans[$identifier->objectNumber->value] = new ObjectByteSpan($identifier, new ByteOffset($auxiliaryOffset), strlen($xrefBytes));
            $bytes .= $xrefBytes;
            $updateOffset = strlen($bytes);
            $hybridExtras = $trailer->with(new Entry(new NameType('Prev'), new IntegerType($offset)))
                ->with(new Entry(new NameType('XRefStm'), new IntegerType($auxiliaryOffset)));
            $updateTrailer = new FileTrailer($fullSize, $root, ...iterator_to_array($hybridExtras->entries(), false));
            $bytes .= (new HybridCrossReferenceSection($section, $identifier->objectNumber))->update()
                . "trailer\n" . $context->serialize($updateTrailer) . "\n";
            $offset = $updateOffset;
        } elseif ($previous->crossReferenceFormat === CrossReferenceFormat::Stream) {
            $identifier = new ObjectIdentifier($firstAvailable);
            $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($offset));
            $section = new CrossReferenceSection(new IntegerType($firstAvailable->increment()->value), $previous->size, $previous->freeListHead, $this->limits, ...$entries);
            $xref = new CrossReferenceStream($section->size, $root, $extras, ...$section->entries());
            $context->register($xref, $identifier);
            $xrefBytes = (new IndirectObject($xref, $identifier))->toPdf($context);
            $spans[$identifier->objectNumber->value] = new ObjectByteSpan($identifier, new ByteOffset(strlen($bytes)), strlen($xrefBytes));
            $bytes .= $xrefBytes;
        } else {
            $section = new CrossReferenceSection(new IntegerType($size), $previous->size, $previous->freeListHead, $this->limits, ...$entries);
            $traditional = [];
            foreach ($section->entries() as $entry) {
                if ($entry instanceof CrossReferenceStreamInUseEntry) {
                    $traditional[] = new CrossReferenceEntry($entry->number(), $entry->byteOffset, $entry->generation, true);
                } elseif ($entry instanceof CrossReferenceStreamFreeEntry) {
                    $traditional[] = new CrossReferenceEntry($entry->number(), new ByteOffset($entry->fields()[1]), $entry->generation, false);
                }
            }
            $fileTrailer = new FileTrailer($section->size, $root, ...iterator_to_array($extras->entries(), false));
            $bytes .= new CrossReferenceTable(...$traditional) . "trailer\n" . $context->serialize($fileTrailer) . "\n";
        }
        $bytes .= "startxref\n" . $offset . "\n%%EOF\n";
        foreach ($context->objects() as $number => $object) {
            $value = $hybrid?->serializedValue($object) ?? $object;
            $effective[$number] = isset($effective[$number])
                ? $effective[$number]->replacedBy($value)
                : new RevisionObject($context->identifier($object), $value, $object);
        }
        return new FileRevision(
            $bytes,
            $previous->header,
            new ByteOffset($offset),
            $section->size,
            $root,
            $trailer,
            $previous->crossReferenceFormat,
            $section->freeListHead(),
            ...[...array_values($effective), ...array_values($spans), ...($previous->encryption === null ? [] : [$previous->encryption])],
        );
    }

    private function validateIdentifierChange(FileRevision $previous, IncrementalUpdate $update): void
    {
        $old = $previous->trailerEntries->get('ID');
        $new = $update->trailerEntries?->get('ID');
        if ($old instanceof ArrayType && $new instanceof ArrayType) {
            $original = $old->items[0];
            $proposed = $new->items[0];
            if (!$original instanceof StringType || !$proposed instanceof StringType || $original->value !== $proposed->value) {
                throw new \InvalidArgumentException('The original file identifier must be preserved across revisions.');
            }
        }
    }

    private function validateEncryptedTrailer(DictionaryType $trailer): void
    {
        $pending = [];
        foreach ($trailer->entries() as $entry) {
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
                array_push($pending, ...$value->items);
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $pending[] = $entry->value;
                }
            }
        }
    }

    private function updatedIdentifier(FileRevision $previous, IncrementalUpdate $update, DictionaryType $trailer, string $bytes, SerializationContext $context): DictionaryType
    {
        $old = $previous->trailerEntries->get('ID');
        if (!$old instanceof ArrayType || $update->trailerEntries?->has('ID')) {
            return $trailer;
        }
        // This is a deterministic file identifier, not an authentication or signature primitive (14.4).
        $changing = substr(hash('sha256', $bytes . $context->serialize($trailer->without('ID')), true), 0, 16);
        return $trailer->with(new Entry(new NameType('ID'), new ArrayType($old->items[0], new HexadecimalString($changing))));
    }
}
