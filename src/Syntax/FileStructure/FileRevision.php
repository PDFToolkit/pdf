<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\PdfFileOutput;
use PDF\Serialization\NativeRevisionEmission;
use PDF\Document\Signatures\PendingSignatureDictionary;
use PDF\Document\Signatures\SignatureByteRange;
use PDF\Document\Signatures\VerifiedRevisionSignature;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;
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
    /** @var list<RevisionReference> */
    private array $referenceAliases;
    public ?FileEncryption $encryption;
    public ObjectIdentifier $pageTreeIdentifier;
    public DictionaryType $trailerEntries;
    public ?FileRevision $previous;
    public FreeListState $freeList;
    /** @var list<RetiredObjectGeneration> */
    private array $retired;
    /** @var array<int, CrossReferenceStreamCompressedEntry> */
    private array $compressed;
    /** @var array<int, true> Numbers allocated from a prior free entry, including unused generation-zero holes. */
    private array $reusedNumbers;
    private ?NativeRevisionEmission $emission;
    public ?VerifiedRevisionSignature $verifiedSignature;

    public function __construct(
        public string $bytes,
        public FileHeader $header,
        public ByteOffset $crossReferenceOffset,
        public IntegerType $size,
        public IndirectReference $root,
        DictionaryType $trailerEntries,
        public CrossReferenceFormat $crossReferenceFormat,
        public IntegerType $freeListHead,
        RevisionObject|RevisionReference|ObjectByteSpan|FileEncryption|GeneratedRevisionTimeline|FreeListState|RetiredObjectGeneration|CrossReferenceStreamCompressedEntry|NativeRevisionEmission|VerifiedRevisionSignature ...$objects,
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
        $timeline = null;
        $references = [];
        $freeList = null;
        $retired = [];
        $compressed = [];
        $emission = null;
        $verified = null;
        foreach ($objects as $object) {
            if ($object instanceof NativeRevisionEmission) {
                if ($emission !== null) {
                    throw new \InvalidArgumentException('One native revision has one emission capability.');
                }
                $emission = $object;
                continue;
            }
            if ($object instanceof VerifiedRevisionSignature) {
                if ($verified !== null) {
                    throw new \InvalidArgumentException('One native revision finalizes one verified signature.');
                }
                $verified = $object;
                continue;
            }
            if ($object instanceof FreeListState) {
                if ($freeList !== null) {
                    throw new \InvalidArgumentException('A revision has one effective free-list index.');
                }
                $freeList = $object;
                continue;
            }
            if ($object instanceof RetiredObjectGeneration) {
                $retired[] = $object;
                continue;
            }
            if ($object instanceof CrossReferenceStreamCompressedEntry) {
                if (isset($compressed[$object->number()])) {
                    throw new \InvalidArgumentException('One effective compressed object has one containing stream.');
                }
                $compressed[$object->number()] = $object;
                continue;
            }
            if ($object instanceof RevisionReference) {
                $references[] = $object;
                continue;
            }
            if ($object instanceof GeneratedRevisionTimeline) {
                if ($timeline !== null) {
                    throw new \InvalidArgumentException('A generated revision has one explicit timeline.');
                }
                $timeline = $object;
                continue;
            }
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
        $deadAliases = new \SplObjectStorage();
        $retiredIdentifiers = [];
        foreach ($retired as $tombstone) {
            $identifier = (string) $tombstone->object->identifier;
            if (isset($retiredIdentifiers[$identifier])) {
                throw new \InvalidArgumentException('Retired generations occur once in the effective history.');
            }
            $retiredIdentifiers[$identifier] = true;
            foreach ($tombstone->object->aliases as $alias) {
                if ($aliases->offsetExists($alias) || $deadAliases->offsetExists($alias)) {
                    throw new \InvalidArgumentException('A retired alias can never become live or belong to another retired generation.');
                }
                $deadAliases->offsetSet($alias);
            }
        }
        $this->retired = $retired;
        foreach ($references as $reference) {
            $binding = $this->generationBinding($reference->reference->target());
            if ((string) $binding->identifier !== (string) $reference->identifier) {
                throw new \InvalidArgumentException('A historical reference must describe its actual effective binding.');
            }
        }
        $this->referenceAliases = $references;
        if ($freeList === null) {
            if ($size->value > (new CrossReferenceLimits())->maximumEntries->value) {
                throw new \InvalidArgumentException('Large revisions require an explicit bounded free-list index.');
            }
            $next = 0;
            $free = [];
            for ($number = $size->integer() - 1; $number > 0; --$number) {
                if (!isset($records[$number])) {
                    $free[] = new CrossReferenceStreamFreeEntry(new IntegerType($number), new IntegerType($next));
                    $next = $number;
                }
            }
            $free[] = new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType($next), new \PDF\Syntax\Object\IndirectObject\GenerationNumber(65535));
            $freeList = new FreeListState($size, ...$free);
        }
        if ($freeList->size->value !== $size->value || $freeList->head()->value !== $freeListHead->value) {
            throw new \InvalidArgumentException('The effective free index matches the exact revision Size and object-zero head.');
        }
        $indexed = array_fill_keys(array_keys($records), true);
        foreach ($freeList->entries() as $entry) {
            if (isset($records[$entry->number()])) {
                throw new \InvalidArgumentException('An effective object number cannot be both live and free.');
            }
            $indexed[$entry->number()] = true;
        }
        if (count($indexed) !== $size->value) {
            throw new \InvalidArgumentException('Every number below Size is either live or an effective free entry.');
        }
        $this->freeList = $freeList;
        foreach ($compressed as $number => $entry) {
            $container = $records[$entry->objectStreamNumber->value] ?? null;
            if (!isset($records[$number]) || $records[$number]->identifier->generationNumber->value !== 0
                || $container === null || $container->identifier->generationNumber->value !== 0
                || !($container->dictionary?->get('Type') instanceof NameType) || $container->dictionary->get('Type')->value !== 'ObjStm') {
                throw new \InvalidArgumentException('Effective compressed objects belong to live generation-zero object streams.');
            }
        }
        $this->compressed = $compressed;
        foreach ($spans as $number => $span) {
            if (!isset($records[$number]) || (string) $records[$number]->identifier !== (string) $span->identifier) {
                throw new \InvalidArgumentException('Every byte span must belong to a live revision object.');
            }
        }
        $this->byteSpans = $spans;
        $this->previous = $timeline?->previous;
        if ($this->previous !== null && !str_starts_with($bytes, $this->previous->bytes)) {
            throw new \InvalidArgumentException('An incremental timeline preserves the entire previous byte prefix.');
        }
        $reusedNumbers = $this->previous->reusedNumbers ?? [];
        foreach ($this->previous?->freeList->entries() ?? [] as $entry) {
            if (isset($records[$entry->number()])) {
                $reusedNumbers[$entry->number()] = true;
            }
        }
        foreach ($records as $record) {
            if ($record->identifier->generationNumber->value > 0) {
                $reusedNumbers[$record->identifier->objectNumber->value] = true;
            }
        }
        $this->reusedNumbers = $reusedNumbers;
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
        $this->emission = $emission;
        $this->verifiedSignature = $verified;
        $verified?->validateBinding($this);
    }

    public function isNativeEmission(): bool
    {
        return $this->emission?->owns($this) ?? false;
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

    /** @return list<RevisionReference> */
    public function references(): array
    {
        return $this->referenceAliases;
    }

    /** @return list<RetiredObjectGeneration> */
    public function retiredObjects(): array
    {
        return $this->retired;
    }

    /** @return array<int, CrossReferenceStreamCompressedEntry> */
    public function compressedObjects(): array
    {
        return $this->compressed;
    }

    /** ISO 7.5.7: even a previously unused hole allocated at generation zero is no longer a new number. */
    public function wasReusedNumber(int $number): bool
    {
        return isset($this->reusedNumbers[$number]);
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
        return (new NativeRevisionEmission(new \PDF\Serialization\ReservedSignatureFinalization($bytes, $replacement), $this))->revision;
    }

    /** @internal A successful reserved finalization establishes a new native emission. */
    public function finalizeReserved(string $bytes, RevisionObject $replacement, NativeRevisionEmission $emission): self
    {
        if (!$this->isNativeEmission()) {
            throw new \LogicException('Reserved finalization requires a native emitted revision.');
        }
        if (strlen($bytes) !== strlen($this->bytes)) {
            throw new \InvalidArgumentException('Reserved-byte finalization must preserve the file length.');
        }
        $old = $this->binding($replacement->identifier);
        $span = $this->span($old->identifier);
        if (!$old->value instanceof PendingSignatureDictionary || !$replacement->dictionary instanceof DictionaryType) {
            throw new \InvalidArgumentException('Reserved finalization replaces its actual pending signature dictionary.');
        }
        $dictionary = $replacement->dictionary;
        $contents = $dictionary->get('Contents');
        $range = $dictionary->get('ByteRange');
        if (!$contents instanceof StringType || strlen($contents->value) !== $old->value->capacity->bytes || !$range instanceof ArrayType) {
            throw new \InvalidArgumentException('Finalized Contents preserves its exact reserved capacity and ByteRange.');
        }
        $context = new SerializationContext(new \PDF\Syntax\Object\IndirectObject\ObjectNumber($this->size->integer()), ...$this->objects());
        $prefix = $span->identifier . " obj\n<< /Contents ";
        $before = $span->offset->value + strlen($prefix);
        $token = '<' . strtoupper(bin2hex($contents->value)) . '>';
        $after = $before + strlen($token);
        $expectedRange = new SignatureByteRange($before, $after, strlen($bytes) - $after);
        if ($range->toPdf($context) !== $expectedRange->object()->toPdf($context)) {
            throw new \InvalidArgumentException('Finalization uses the actual generated complete-file Contents gap.');
        }
        $frozen = $old->dictionary ?? throw new \LogicException('Pending signatures retain their emitted dictionary.');
        $keys = [];
        foreach ([$frozen, $dictionary] as $candidate) {
            foreach ($candidate->entries() as $entry) {
                $keys[$entry->key->value] = true;
            }
        }
        foreach (array_keys($keys) as $key) {
            $oldValue = $frozen->get($key);
            $newValue = $dictionary->get($key);
            if (!in_array($key, ['Contents', 'ByteRange'], true) && ($oldValue === null ? $newValue !== null : ($newValue === null || $oldValue->toPdf($context) !== $newValue->toPdf($context)))) {
                throw new \InvalidArgumentException('Finalization cannot replace the frozen signature policies or metadata.');
            }
        }
        $expected = substr_replace($this->bytes, $token, $before, $after - $before);
        $rangeOffset = $after + strlen(' /ByteRange ');
        $reservedRange = $old->value->byteRange->toPdf($context);
        $expected = substr_replace($expected, $old->value->byteRange->filled($expectedRange), $rangeOffset, strlen($reservedRange));
        if ($bytes !== $expected) {
            throw new \InvalidArgumentException('Finalization changes only the exact Contents and ByteRange reservations.');
        }
        if (substr($bytes, 0, $span->offset->value) !== substr($this->bytes, 0, $span->offset->value)
            || substr($bytes, $span->offset->value + $span->length) !== substr($this->bytes, $span->offset->value + $span->length)) {
            throw new \InvalidArgumentException('Finalization may change only its reserved indirect object.');
        }
        $records = $this->effectiveObjects;
        $records[$old->identifier->objectNumber->value] = $old->replacedBy($dictionary);
        return new self(
            $bytes,
            $this->header,
            $this->crossReferenceOffset,
            $this->size,
            $this->root,
            $this->trailerEntries,
            $this->crossReferenceFormat,
            $this->freeListHead,
            ...[$emission, ...array_values($records), ...$this->spans(), ...$this->references(), ...$this->retiredObjects(), ...array_values($this->compressedObjects()), $this->freeList, ...($this->encryption === null ? [] : [$this->encryption]), new GeneratedRevisionTimeline($this->previous)]
        );
    }

    public function withVerifiedSignature(VerifiedRevisionSignature $signature): self
    {
        return (new NativeRevisionEmission(new \PDF\Serialization\VerifiedSignatureAttachment($signature), $this))->revision;
    }

    /** @internal Attach mathematical evidence without changing any bytes or frozen objects. */
    public function attachVerifiedSignature(VerifiedRevisionSignature $signature, NativeRevisionEmission $emission): self
    {
        if (!$this->isNativeEmission()) {
            throw new \LogicException('Signature evidence belongs to an actual native emission.');
        }
        $signature->validateBinding($this);
        return new self(
            $this->bytes,
            $this->header,
            $this->crossReferenceOffset,
            $this->size,
            $this->root,
            $this->trailerEntries,
            $this->crossReferenceFormat,
            $this->freeListHead,
            ...[$emission, $signature, ...$this->objects(), ...$this->spans(), ...$this->references(), ...$this->retiredObjects(), ...array_values($this->compressedObjects()), $this->freeList, ...($this->encryption === null ? [] : [$this->encryption]), new GeneratedRevisionTimeline($this->previous)]
        );
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
        $binding = $this->generationBinding($value);
        return new IndirectReference($binding->value, $binding->identifier);
    }

    /** Resolve a known retired generation only for an obsolete reference (ISO 7.3.9, PDF p29). */
    private function generationBinding(BasicType|ObjectIdentifier $value): RevisionObject
    {
        try {
            return $this->binding($value);
        } catch (\OutOfBoundsException $error) {
            foreach ($this->retired as $tombstone) {
                if ($value instanceof ObjectIdentifier
                    ? (string) $value === (string) $tombstone->object->identifier
                    : in_array($value, $tombstone->object->aliases, true)) {
                    return $tombstone->object;
                }
            }
            throw $error;
        }
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
