<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\PdfFileOutput;
use PDF\Security\FileEncryption;
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
    /** @var array<int, ObjectByteSpan> */
    private array $byteSpans;
    public ?FileEncryption $encryption;
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
        RevisionObject|ObjectByteSpan|FileEncryption ...$objects,
    ) {
        $this->trailerEntries = new DictionaryType(...iterator_to_array($trailerEntries->entries(), false));
        if (!str_starts_with($bytes, (string) $header) || $crossReferenceOffset->value >= strlen($bytes)
            || !str_ends_with($bytes, "startxref\n" . $crossReferenceOffset->value . "\n%%EOF\n")) {
            throw new \InvalidArgumentException('Revision metadata must describe the supplied serialized bytes.');
        }
        if ($size->value < 2 || $freeListHead->value < 0 || $freeListHead->value >= $size->value) {
            throw new \InvalidArgumentException('Invalid revision Size or free-list head.');
        }
        foreach (['Size', 'Root', 'Prev', 'XRefStm'] as $managed) {
            if ($trailerEntries->has($managed)) {
                throw new \InvalidArgumentException('Managed or unsupported revision trailer entry: ' . $managed);
            }
        }
        $records = [];
        $spans = [];
        $encryption = null;
        $aliases = new \SplObjectStorage();
        foreach ($objects as $object) {
            if ($object instanceof FileEncryption) {
                if ($encryption !== null) {
                    throw new \InvalidArgumentException('A revision has exactly one encryption operation.');
                }
                $encryption = $object;
                continue;
            }
            if ($object instanceof ObjectByteSpan) {
                $number = $object->identifier->objectNumber->value;
                if (isset($spans[$number]) || $object->offset->value + $object->length > strlen($bytes)
                    || substr($bytes, $object->offset->value, strlen((string) $object->identifier) + 5) !== $object->identifier . " obj\n") {
                    throw new \InvalidArgumentException('Revision spans must identify the exact generated indirect object bytes.');
                }
                $spans[$number] = $object;
                continue;
            }
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
        foreach ($spans as $number => $span) {
            if (!isset($records[$number]) || (string) $records[$number]->identifier !== (string) $span->identifier) {
                throw new \InvalidArgumentException('Every byte span must belong to a live revision object.');
            }
        }
        $this->byteSpans = $spans;
        $encrypt = $this->trailerEntries->get('Encrypt');
        if ($encryption === null ? $encrypt !== null : (!$encrypt instanceof IndirectReference || $encrypt->target() !== $encryption->dictionary())) {
            throw new \InvalidArgumentException('Revision encryption must match its exact Encrypt dictionary reference.');
        }
        if ($encryption !== null && $this->binding($encryption->dictionary())->value !== $encryption->dictionary()) {
            throw new \InvalidArgumentException('The effective Encrypt binding must preserve the file encryption dictionary.');
        }
        $this->encryption = $encryption;
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

    /** @return list<ObjectByteSpan> */
    public function spans(): array
    {
        return array_values($this->byteSpans);
    }

    public function span(BasicType|ObjectIdentifier $value): ObjectByteSpan
    {
        $record = $this->binding($value);
        return $this->byteSpans[$record->identifier->objectNumber->value]
            ?? throw new \OutOfBoundsException('No ordinary indirect byte span exists for this value; it may be compressed.');
    }

    /** Replace reserved bytes without moving objects or changing reference identities. */
    public function withReservedBytes(string $bytes, RevisionObject $replacement): self
    {
        if (strlen($bytes) !== strlen($this->bytes)) {
            throw new \InvalidArgumentException('Reserved-byte finalization must preserve the file length.');
        }
        $old = $this->binding($replacement->identifier);
        $span = $this->span($old->identifier);
        if (substr($bytes, 0, $span->offset->value) !== substr($this->bytes, 0, $span->offset->value)
            || substr($bytes, $span->offset->value + $span->length) !== substr($this->bytes, $span->offset->value + $span->length)) {
            throw new \InvalidArgumentException('Finalization may change only its reserved indirect object.');
        }
        $records = $this->effectiveObjects;
        $records[$old->identifier->objectNumber->value] = $replacement;
        return new self($bytes, $this->header, $this->crossReferenceOffset, $this->size, $this->root,
            $this->trailerEntries, $this->crossReferenceFormat, $this->freeListHead,
            ...[...array_values($records), ...$this->spans(), ...($this->encryption === null ? [] : [$this->encryption])]);
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
