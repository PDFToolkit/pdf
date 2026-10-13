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

/** Stateful serialization belongs to a per-call context, separate from the file model. */
final class FileWriter
{
    public function __construct(private readonly CrossReferenceLimits $limits = new CrossReferenceLimits())
    {
    }

    public function serialize(FileStructure $file): string
    {
        return $this->revision($file)->bytes;
    }

    public function revision(FileStructure $file): FileRevision
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
        $context = new SerializationContext();
        $context->prepareFile($file->body, $root, $file->trailerEntries);
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
            $xref = new CrossReferenceStream($section->size, $root, $file->trailerEntries, ...$section->entries());
            $context->register($xref, $identifier);
            $bytes = $text . (new IndirectObject($xref, $identifier))->toPdf($context)
                . "startxref\n" . $xrefOffset . "\n%%EOF\n";
            return $this->snapshot($file, $context, $bytes, $xrefOffset, $section);
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
        $trailer = new FileTrailer(new IntegerType($size), $root, ...($file->trailerEntries === null ? [] : iterator_to_array($file->trailerEntries->entries(), false)));
        $bytes = $text . new CrossReferenceTable(...$traditional) . "trailer\n" . $context->serialize($trailer)
            . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
        return $this->snapshot($file, $context, $bytes, $xrefOffset, $section);
    }

    private function snapshot(FileStructure $file, SerializationContext $context, string $bytes, int $offset, CrossReferenceSection $section): FileRevision
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
            $file->trailerEntries ?? new DictionaryType(),
            $file->crossReferenceFormat,
            $section->freeListHead(),
            ...$objects,
        );
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
