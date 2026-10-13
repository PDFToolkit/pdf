<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\PdfFileOutput;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Frozen serialized bytes and document-scoped reference bindings; this is not a PDF parser. */
final readonly class FileRevision implements \Stringable
{
    /** @var array<int, RevisionObject> */
    private array $effectiveObjects;
    public ObjectIdentifier $pageTreeIdentifier;
    public DictionaryType $trailerEntries;

    public function __construct(
        public string $bytes,
        public FileHeader $header,
        public ByteOffset $crossReferenceOffset,
        public IntegerType $size,
        public IndirectReference $root,
        DictionaryType $trailerEntries,
        public CrossReferenceFormat $crossReferenceFormat,
        public IntegerType $freeListHead,
        RevisionObject ...$objects,
    ) {
        $this->trailerEntries = new DictionaryType(...iterator_to_array($trailerEntries->entries(), false));
        if (!str_starts_with($bytes, (string) $header) || $crossReferenceOffset->value >= strlen($bytes)
            || !str_ends_with($bytes, "startxref\n" . $crossReferenceOffset->value . "\n%%EOF\n")) {
            throw new \InvalidArgumentException('Revision metadata must describe the supplied serialized bytes.');
        }
        if ($size->value < 2 || $freeListHead->value < 0 || $freeListHead->value >= $size->value) {
            throw new \InvalidArgumentException('Invalid revision Size or free-list head.');
        }
        foreach (['Size', 'Root', 'Prev', 'XRefStm', 'Encrypt'] as $managed) {
            if ($trailerEntries->has($managed)) {
                throw new \InvalidArgumentException('Managed or unsupported revision trailer entry: ' . $managed);
            }
        }
        $records = [];
        $aliases = new \SplObjectStorage();
        foreach ($objects as $object) {
            $number = $object->identifier->objectNumber->value;
            if ($number >= $size->value || isset($records[$number])) {
                throw new \InvalidArgumentException('Revision object numbers must be unique and smaller than Size.');
            }
            foreach ($object->aliases as $alias) {
                if ($aliases->offsetExists($alias)) {
                    throw new \InvalidArgumentException('One revision identity cannot have multiple identifiers.');
                }
                $aliases->offsetSet($alias);
            }
            $records[$number] = $object;
        }
        if ($freeListHead->integer() !== 0 && isset($records[$freeListHead->integer()])) {
            throw new \InvalidArgumentException('The free-list head cannot refer to a live object.');
        }
        ksort($records, SORT_NUMERIC);
        $this->effectiveObjects = $records;
        $binding = $this->binding($root->target());
        if ($root->identifier !== null && (string) $binding->identifier !== (string) $root->identifier) {
            throw new \InvalidArgumentException('The revision root has a conflicting identifier.');
        }
        $type = $binding->dictionary?->get('Type');
        $pages = $binding->dictionary?->get('Pages');
        if (!$type instanceof NameType || $type->value !== 'Catalog' || !$pages instanceof IndirectReference) {
            throw new \InvalidArgumentException('The revision root must describe a catalog and page-tree reference.');
        }
        $pageTree = $this->binding($pages->target());
        $pagesType = $pageTree->dictionary?->get('Type');
        if (!$pagesType instanceof NameType || $pagesType->value !== 'Pages' || $pageTree->dictionary?->has('Parent')) {
            throw new \InvalidArgumentException('The effective page-tree root must be a /Pages dictionary without Parent.');
        }
        if ($pages->identifier !== null && (string) $pages->identifier !== (string) $pageTree->identifier) {
            throw new \InvalidArgumentException('The page-tree reference has a conflicting identifier.');
        }
        $this->pageTreeIdentifier = $pageTree->identifier;
    }

    /** @return list<RevisionObject> */
    public function objects(): array
    {
        return array_values($this->effectiveObjects);
    }

    public function binding(BasicType|ObjectIdentifier $value): RevisionObject
    {
        if ($value instanceof ObjectIdentifier) {
            $record = $this->effectiveObjects[$value->objectNumber->value] ?? null;
            if ($record === null || (string) $record->identifier !== (string) $value) {
                throw new \OutOfBoundsException('The identifier does not belong to this revision.');
            }
            return $record;
        }
        foreach ($this->effectiveObjects as $record) {
            if (in_array($value, $record->aliases, true)) {
                return $record;
            }
        }
        throw new \OutOfBoundsException('The object does not belong to this revision.');
    }

    /** Return an ordinary typed reference to the effective value, never a target-less placeholder. */
    public function reference(BasicType|ObjectIdentifier $value): IndirectReference
    {
        $binding = $this->binding($value);
        return new IndirectReference($binding->value, $binding->identifier);
    }

    public function writeTo(string $path): void
    {
        (new PdfFileOutput())->write($path, $this->bytes);
    }

    public function __toString(): string
    {
        return $this->bytes;
    }
}
