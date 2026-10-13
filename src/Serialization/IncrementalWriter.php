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
        $context = new SerializationContext(new ObjectNumber($previous->size->integer()), ...$previous->objects());
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
        $pagesIdentifier = $context->reference($catalogDictionary->get('Pages'));
        $pagesDictionary = ($effective[$pagesIdentifier->objectNumber->value] ?? null)?->dictionary;
        if (!$pagesDictionary?->get('Type') instanceof NameType || $pagesDictionary->get('Type')->value !== 'Pages' || $pagesDictionary->has('Parent')) {
            throw new \LogicException('The effective Pages reference must remain a root /Pages dictionary.');
        }
        $compressed = [];
        foreach ($context->objects() as $object) {
            if (!$object instanceof ObjectStream) {
                continue;
            }
            if ($previous->crossReferenceFormat !== CrossReferenceFormat::Stream) {
                throw new \LogicException('Object streams require the inherited cross-reference-stream format.');
            }
            $container = $context->identifier($object);
            if ($container->generationNumber->value !== 0) {
                throw new \LogicException('An object stream must have generation zero.');
            }
            foreach ($object->embeddedEntries() as $index => $member) {
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
        $bytes = $previous->bytes;
        $entries = array_values($compressed);
        $written = array_fill_keys(array_keys($compressed), true);
        do {
            foreach (array_diff_key($context->objects(), $written) as $number => $object) {
                if ($object instanceof CrossReferenceStream) {
                    throw new \LogicException('Cross-reference streams belong to the incremental writer.');
                }
                $identifier = $context->identifier($object);
                $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset(strlen($bytes)), $identifier->generationNumber);
                $bytes .= (new IndirectObject($object, $identifier))->toPdf($context);
                $written[$number] = true;
            }
        } while (array_diff_key($context->objects(), $written) !== []);
        $offset = strlen($bytes);
        $maximum = max([$previous->size->integer() - 1, ...array_keys($context->objects())]);
        $reserve = $previous->crossReferenceFormat === CrossReferenceFormat::Stream ? 2 : 1;
        if ($maximum > PHP_INT_MAX - $reserve) {
            throw new \OverflowException('Not enough object number space for an incremental revision.');
        }
        $firstAvailable = (new ObjectNumber($maximum))->increment();
        $size = $firstAvailable->value;
        $trailer = $this->updatedIdentifier($previous, $update, $trailer, $bytes, $context);
        $extras = $trailer->with(new Entry(new NameType('Prev'), new IntegerType($previous->crossReferenceOffset->value)));
        $root = new IndirectReference($catalog, $rootIdentifier);
        if ($previous->crossReferenceFormat === CrossReferenceFormat::Stream) {
            $identifier = new ObjectIdentifier($firstAvailable);
            $entries[] = new CrossReferenceStreamInUseEntry($identifier->objectNumber, new ByteOffset($offset));
            $section = new CrossReferenceSection(new IntegerType($firstAvailable->increment()->value), $previous->size, $previous->freeListHead, $this->limits, ...$entries);
            $xref = new CrossReferenceStream($section->size, $root, $extras, ...$section->entries());
            $context->register($xref, $identifier);
            $bytes .= (new IndirectObject($xref, $identifier))->toPdf($context);
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
            $effective[$number] = isset($effective[$number])
                ? $effective[$number]->replacedBy($object)
                : new RevisionObject($context->identifier($object), $object);
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
            ...array_values($effective),
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
