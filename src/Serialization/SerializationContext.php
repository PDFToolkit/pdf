<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\FileStructure\RevisionValueSnapshot;
use PDF\Syntax\FileStructure\SerializedRevisionValue;
use PDF\Syntax\FileStructure\RevisionReference;
use PDF\Syntax\FileStructure\RetiredObjectGeneration;
use PDF\Syntax\FileStructure\FreeListState;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\FileStructure\CrossReferenceStreamObject;
use PDF\Security\FileEncryption;
use PDF\Security\StreamCryptFilter;
use PDF\Syntax\Linearization\ObjectIdentifierRemapping;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Filters\Crypt;
use PDF\Security\DocumentMetadataExemption;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Document\Files\AssociatedFilesMarkedContentFacts;
use PDF\Document\Metadata\MetadataMarkedContentFacts;

/** An identity map and serialization guard scoped to one PDF file. */
final class SerializationContext
{
    /** @var \SplObjectStorage<BasicType, ObjectIdentifier> */
    private \SplObjectStorage $identifiers;
    /** @var array<int, BasicType> */
    private array $objects = [];
    /** @var \SplObjectStorage<BasicType, null> */
    private \SplObjectStorage $active;
    private int $nextNumber = 1;
    /** @var \SplObjectStorage<BasicType, null> */
    private \SplObjectStorage $historical;
    /** @var array<int, ObjectIdentifier> */
    private array $reservedIdentifiers = [];
    /** @var array<int, RevisionObject> */
    private array $historicalObjects = [];
    /** @var array<string, RevisionObject> Actual preceding values, including now-retired generations. */
    private array $historicalGenerations = [];
    private readonly int $firstNewNumber;
    private readonly ?FileEncryption $encryption;
    private ?DocumentMetadataExemption $metadataExemption;
    private ?BasicType $indirectValue = null;
    private ?ObjectIdentifier $containingIdentifier = null;
    private int $encryptionSuppression = 0;
    private readonly bool $remapping;
    /** @var array<string, RevisionStreamSnapshot> Emissions are scoped to the full number/generation pair. */
    private array $streamEmissions = [];
    /** @var array<string, array{ContentStreams, AssociatedFilesMarkedContentFacts, MetadataMarkedContentFacts}> */
    private array $nativeContentEmissions = [];
    /** @var \SplObjectStorage<IndirectReference, ObjectIdentifier> */
    private \SplObjectStorage $referenceAliases;
    /** @var \SplObjectStorage<BasicType, ObjectIdentifier> */
    private \SplObjectStorage $retiredAliases;
    private readonly ?FreeListState $freeList;

    public function __construct(ObjectNumber $firstNewNumber = new ObjectNumber(), RevisionObject|RevisionReference|RetiredObjectGeneration|FreeListState|FileEncryption|DocumentMetadataExemption|ObjectIdentifierRemapping ...$existingObjects)
    {
        $this->identifiers = new \SplObjectStorage();
        $this->active = new \SplObjectStorage();
        $this->historical = new \SplObjectStorage();
        $this->referenceAliases = new \SplObjectStorage();
        $this->retiredAliases = new \SplObjectStorage();
        $this->firstNewNumber = $firstNewNumber->value;
        $this->nextNumber = $this->firstNewNumber;
        $encryption = null;
        $metadata = null;
        $remapping = false;
        $freeList = null;
        foreach ($existingObjects as $record) {
            if ($record instanceof FreeListState) {
                if ($freeList !== null || $record->size->value !== $firstNewNumber->value) {
                    throw new \InvalidArgumentException('A historical free index has the exact preceding Size and occurs once.');
                }
                $freeList = $record;
                continue;
            }
            if ($record instanceof RetiredObjectGeneration) {
                $this->historicalGenerations[(string) $record->object->identifier] = $record->object;
                foreach ($record->object->aliases as $alias) {
                    if ($this->identifiers->offsetExists($alias)) {
                        throw new \InvalidArgumentException('A retired alias cannot also belong to another current or retired generation.');
                    }
                    $this->identifiers[$alias] = $record->object->identifier;
                    $this->retiredAliases[$alias] = $record->object->identifier;
                    $this->historical->offsetSet($alias);
                }
                continue;
            }
            if ($record instanceof RevisionReference) {
                if ($this->referenceAliases->offsetExists($record->reference)) {
                    throw new \InvalidArgumentException('Historical reference instances are bound once.');
                }
                $this->referenceAliases[$record->reference] = $record->identifier;
                continue;
            }
            if ($record instanceof ObjectIdentifierRemapping) {
                if ($remapping) {
                    throw new \InvalidArgumentException('Object identifier remapping is selected once per context.');
                }
                $remapping = true;
                continue;
            }
            if ($record instanceof DocumentMetadataExemption) {
                if ($metadata !== null) {
                    throw new \InvalidArgumentException('Only one document-level metadata stream can be exempted.');
                }
                $metadata = $record;
                continue;
            }
            if ($record instanceof FileEncryption) {
                if ($encryption !== null) {
                    throw new \InvalidArgumentException('One serialization context can use only one file encryption operation.');
                }
                $encryption = $record;
                continue;
            }
            $number = $record->identifier->objectNumber->value;
            if ($number >= $this->firstNewNumber || isset($this->reservedIdentifiers[$number])) {
                throw new \InvalidArgumentException('Existing object numbers must be unique and precede the first new number.');
            }
            $this->reservedIdentifiers[$number] = $record->identifier;
            $this->historicalObjects[$number] = $record;
            $this->historicalGenerations[(string) $record->identifier] = $record;
            foreach ($record->aliases as $alias) {
                if ($this->identifiers->offsetExists($alias)) {
                    throw new \InvalidArgumentException('Existing identities must have a single identifier.');
                }
                $this->identifiers[$alias] = $record->identifier;
                $this->historical->offsetSet($alias);
            }
        }
        if ($remapping && $this->reservedIdentifiers !== []) {
            throw new \LogicException('Initial-file identifier remapping cannot reuse revision snapshots.');
        }
        if ($metadata !== null && ($encryption === null || $encryption->metadata()->value)) {
            throw new \LogicException('A document metadata exemption requires EncryptMetadata=false.');
        }
        $this->encryption = $encryption;
        $this->metadataExemption = $metadata;
        $this->remapping = $remapping;
        $this->freeList = $freeList;
    }

    /** Discover explicit identifiers before assigning automatic numbers. */
    public function prepare(BasicType|IndirectObject ...$roots): void
    {
        $this->discover(array_values($roots), []);
    }

    /** Discover trailer values without promoting direct values to orphan indirect objects. */
    public function prepareFile(FileBody $body, IndirectReference $root, ?DictionaryType $trailer = null): void
    {
        $values = [$root];
        foreach ($trailer?->entries() ?? [] as $entry) {
            $values[] = $entry->value;
        }
        $this->discover($body->objects(), $values);
    }

    /**
     * @param list<BasicType|IndirectObject> $roots
     * @param list<BasicType> $values
     */
    private function discover(array $roots, array $values): void
    {
        // entries() may create temporary arrays/dictionaries. Keep those values
        // alive throughout discovery so PHP cannot recycle an earlier object ID.
        $seen = new \SplObjectStorage();
        $targets = [];
        $visit = function (BasicType $value) use (&$visit, &$seen, &$targets): void {
            if ($value instanceof IndirectReference) {
                if ($this->historical->offsetExists($value->target())) {
                    $this->reference($value);
                    return;
                }
                $targets[spl_object_id($value->target())] = $value->target();
                if ($value->identifier !== null && !$this->remapping) {
                    $this->register($value->target(), $value->identifier);
                }
                $visit($value->target());
                return;
            }
            if ($seen->offsetExists($value)) {
                return;
            }
            $seen->offsetSet($value);
            if ($value instanceof ObjectStream) {
                if ($this->remapping) {
                    throw new \LogicException('Embedded object numbers require an explicit object-stream remapping plan.');
                }
                foreach ($value->embeddedEntries() as $member) {
                    $this->stage(new IndirectObject($member->value, $member->identifier()));
                    $visit($member->value);
                }
            }
            if ($value instanceof ArrayType) {
                foreach ($value->items as $item) {
                    $visit($item);
                }
            }
            if ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $visit($entry->value);
                }
            }
            if ($value instanceof StreamObject) {
                $visit($value->dictionary());
                foreach ($value->filters as $filter) {
                    $parameters = $filter->parameters();
                    if ($parameters !== null) {
                        $visit($parameters);
                    }
                }
            }
        };
        foreach ($roots as $root) {
            if ($root instanceof IndirectObject) {
                if ($this->remapping) {
                    $this->register($root->value);
                } else {
                    $this->stage($root);
                }
                $root = $root->value;
            }
            if (!$root instanceof IndirectReference) {
                $targets[spl_object_id($root)] = $root;
            }
            $visit($root);
        }
        foreach ($values as $value) {
            $visit($value);
        }
        foreach ($targets as $target) {
            $this->register($target);
        }
    }

    public function register(BasicType $object, ?ObjectIdentifier $identifier = null): ObjectIdentifier
    {
        if ($this->retiredAliases->offsetExists($object)) {
            throw new \LogicException('A retired construction alias cannot be registered as a live object again.');
        }
        if ($object instanceof IndirectReference) {
            throw new \InvalidArgumentException('Indirect objects cannot contain references as their entire value.');
        }
        if ($this->identifiers->offsetExists($object)) {
            $existing = $this->identifiers[$object];
            if ($identifier !== null && (string) $existing !== (string) $identifier) {
                throw new \LogicException('One object has conflicting identifiers.');
            }
            return $existing;
        }
        if ($identifier === null) {
            while (isset($this->objects[$this->nextNumber])) {
                if ($this->nextNumber === PHP_INT_MAX) {
                    throw new \OverflowException('No automatic object number remains.');
                }
                ++$this->nextNumber;
            }
            if ($this->nextNumber === PHP_INT_MAX) {
                throw new \OverflowException('No automatic object number remains.');
            }
            $identifier = new ObjectIdentifier(new ObjectNumber($this->nextNumber++));
        }
        $number = $identifier->objectNumber->value;
        if ($number < $this->firstNewNumber) {
            throw new \LogicException('Existing objects must be explicitly staged with their current identifier.');
        }
        if ($identifier->generationNumber->value === 65535) {
            throw new \InvalidArgumentException('Generation 65535 is reserved for free objects.');
        }
        if (isset($this->objects[$number])) {
            throw new \LogicException('Duplicate indirect object number: ' . $number);
        }
        $this->identifiers[$object] = $identifier;
        $this->objects[$number] = $object;
        return $identifier;
    }

    /** Select an explicit addition or replacement; historical references alone never stage a value. */
    public function stage(IndirectObject $object): ObjectIdentifier
    {
        $identifier = $object->identifier;
        $number = $identifier->objectNumber->value;
        if ($this->retiredAliases->offsetExists($object->value)) {
            throw new \LogicException('A retired construction alias cannot be staged as a live object again.');
        }
        if ($object->value instanceof IndirectReference || $identifier->generationNumber->value === 65535) {
            throw new \InvalidArgumentException('A staged object requires a live indirect value.');
        }
        if ($number < $this->firstNewNumber) {
            $reserved = $this->reservedIdentifiers[$number] ?? null;
            if ($reserved === null || (string) $reserved !== (string) $identifier) {
                throw new \LogicException('An update cannot reuse a free number or change a live generation.');
            }
        }
        if (isset($this->objects[$number]) && $this->objects[$number] !== $object->value) {
            throw new \LogicException('One indirect number cannot have multiple staged values.');
        }
        if ($this->identifiers->offsetExists($object->value) && (string) $this->identifiers[$object->value] !== (string) $identifier) {
            throw new \LogicException('One object has conflicting identifiers.');
        }
        $this->identifiers[$object->value] = $identifier;
        $this->objects[$number] = $object->value;
        return $identifier;
    }

    /** A reuse is authorized only against the preceding effective free index. */
    public function stageReused(IndirectObject $object): ObjectIdentifier
    {
        $free = $this->freeList?->entry($object->identifier->objectNumber)
            ?? throw new \LogicException('Explicit reuse requires a historical free index.');
        $number = $object->identifier->objectNumber->value;
        if ($free->generation->value === 65535 || (string) $free->generation !== (string) $object->identifier->generationNumber
            || isset($this->reservedIdentifiers[$number]) || $this->knownIdentifier($object->value) !== null) {
            throw new \LogicException('A reuse uses its exact free generation and a new identity.');
        }
        $this->reservedIdentifiers[$number] = $object->identifier;
        return $this->stage($object);
    }

    public function isHistorical(BasicType $object): bool
    {
        return $this->historical->offsetExists($object);
    }

    public function reference(IndirectReference $reference): ObjectIdentifier
    {
        if ($this->referenceAliases->offsetExists($reference)) {
            $identifier = $this->referenceAliases[$reference];
            $known = $this->knownIdentifier($reference->target());
            if ($known === null || (string) $known !== (string) $identifier) {
                throw new \LogicException('A historical reference must retain its actual emitted target identifier.');
            }
            return $identifier;
        }
        if ($this->retiredAliases->offsetExists($reference->target())) {
            $identifier = $this->retiredAliases[$reference->target()];
            if ($reference->identifier !== null && (string) $reference->identifier !== (string) $identifier) {
                throw new \LogicException('An obsolete reference retains its retired generation, never the reused generation.');
            }
            $this->referenceAliases[$reference] = $identifier;
            return $identifier;
        }
        $identifier = $this->register($reference->target(), $this->remapping ? null : $reference->identifier);
        $this->referenceAliases[$reference] = $identifier;
        return $identifier;
    }

    /** @return list<RevisionReference> */
    public function references(): array
    {
        $records = [];
        foreach ($this->referenceAliases as $reference) {
            $records[] = new RevisionReference($reference, $this->referenceAliases[$reference]);
        }
        return $records;
    }
    public function referenceTo(BasicType $target): IndirectReference
    {
        return new IndirectReference($target);
    }
    /** @return array<int, BasicType> */
    public function objects(): array
    {
        return $this->objects;
    }
    public function identifier(BasicType $object): ObjectIdentifier
    {
        return $this->knownIdentifier($object) ?? $this->register($object);
    }

    /** Inspect an existing identity without allocating or discovering another object. */
    public function knownIdentifier(BasicType $object): ?ObjectIdentifier
    {
        return $this->identifiers->offsetExists($object) ? $this->identifiers[$object] : null;
    }

    /** Resolve a known generation without allocating unknown targets or reparsing historical builders. */
    public function effectiveReferenceValue(IndirectReference $reference): BasicType
    {
        if ($this->knownIdentifier($reference->target()) === null) {
            return $reference->target();
        }
        $identifier = $this->reference($reference);
        $number = $identifier->objectNumber->value;
        $staged = $this->objects[$number] ?? null;
        if ($staged !== null) {
            return (string) $this->knownIdentifier($staged) === (string) $identifier ? $staged : new NullType();
        }
        $historical = $this->historicalObjects[$number] ?? null;
        return $historical !== null && (string) $historical->identifier === (string) $identifier ? $historical->snapshot : new NullType();
    }

    /** An unchanged emitted stream must not lose the declaration that made its retained bytes external. */
    public function wasSerializedExternalStream(StreamObject $stream): bool
    {
        $identifier = $this->knownIdentifier($stream);
        if ($identifier === null || isset($this->objects[$identifier->objectNumber->value])) {
            return false; // Newly staged bytes use the current effective F, including null.
        }
        $record = $this->historicalGenerations[(string) $identifier] ?? null;
        if (!$record?->snapshot instanceof RevisionStreamSnapshot) {
            return false;
        }
        $file = $record->dictionary?->get('F');
        if ($file instanceof IndirectReference) {
            $previousIdentifier = $file->identifier;
            $file = $previousIdentifier === null ? null : ($this->historicalGenerations[(string) $previousIdentifier]->snapshot ?? null);
        }
        return $file instanceof StringType || $file instanceof DictionaryType;
    }

    /** Called by StreamObject with the actual final dictionary and same-call pre-encryption bytes. */
    public function recordStreamEmission(StreamObject $stream, DictionaryType $dictionary, string $encoded): void
    {
        $identifier = $this->containingIdentifier ?? $this->knownIdentifier($this->indirectValue ?? $stream);
        if ($identifier === null) {
            // A public toPdf() call may render only the unbound stream body.
            // There is no file-scoped indirect emission to snapshot in that case.
            return;
        }
        $frozen = (new RevisionValueSnapshot($this))->capture($dictionary);
        if (!$frozen instanceof DictionaryType) {
            throw new \LogicException('An emitted stream dictionary must freeze as a dictionary.');
        }
        $native = $this->nativeContentEmissions[(string) $identifier] ?? null;
        $facts = $native !== null && $stream->data === $native[0] ? $native[1] : null;
        $metadataFacts = $facts === null ? null : $native[2];
        $this->streamEmissions[(string) $identifier] = new RevisionStreamSnapshot($frozen, $encoded, $facts, $metadataFacts);
        unset($this->nativeContentEmissions[(string) $identifier]);
    }

    public function serializedSnapshot(BasicType $value, ?ObjectIdentifier $identifier = null): SerializedRevisionValue
    {
        $identifier ??= $this->knownIdentifier($value);
        if ($value instanceof StreamObject) {
            $emitted = $identifier === null ? null : ($this->streamEmissions[(string) $identifier] ?? null);
            return new SerializedRevisionValue($emitted ?? throw new \LogicException('A stream snapshot requires its actual recorded emission.'));
        }
        return new SerializedRevisionValue((new RevisionValueSnapshot($this))->capture($value));
    }

    public function serialize(BasicType $object): string
    {
        if ($object instanceof StreamObject) {
            throw new \LogicException('Streams must be indirect objects (7.3.8).');
        }
        return $this->guarded($object);
    }
    /** A normalized copy may use its containing object's identifier without acquiring a second identity. */
    public function serializeIndirect(BasicType $object, ?ObjectIdentifier $containingIdentifier = null): string
    {
        // A standalone indirect stream has no earlier discovery pass. Bind it
        // before encoding/capturing so its emission and encrypted dictionary
        // strings share one identifier. Explicit proxies retain the supplied ID.
        $containingIdentifier ??= $object instanceof StreamObject ? $this->identifier($object) : null;
        $previous = $this->indirectValue;
        $previousIdentifier = $this->containingIdentifier;
        $this->indirectValue = $object;
        $this->containingIdentifier = $containingIdentifier;
        try {
            return $this->guarded($object);
        } finally {
            $this->indirectValue = $previous;
            $this->containingIdentifier = $previousIdentifier;
        }
    }

    /** Select the effective root metadata after incremental bindings have been staged. */
    public function excludeDocumentMetadata(DocumentMetadataExemption $exemption): void
    {
        if ($this->encryption === null || $this->encryption->metadata()->value
            || ($this->metadataExemption !== null && $this->metadataExemption->stream !== $exemption->stream)) {
            throw new \LogicException('One document metadata exemption requires EncryptMetadata=false.');
        }
        $this->metadataExemption = $exemption;
    }

    /** Signature container Contents stay clear; this also matches qpdf's signature handling. */
    public function serializeEntry(DictionaryType $dictionary, Entry $entry): string
    {
        $value = $this->encrypting() && $entry->key->value === 'Contents' && $entry->value instanceof StringType && $this->isByteRangeSignature($dictionary)
            ? $this->serializeStreamValue(new HexadecimalString($entry->value->value)) : $this->serialize($entry->value);
        return $this->serialize($entry->key) . ' ' . $value;
    }

    /** Recognizes byte-range signature containers; object signatures without ByteRange use normal encryption. */
    public function isByteRangeSignature(DictionaryType $dictionary): bool
    {
        $type = $dictionary->get('Type');
        if ($type instanceof IndirectReference) {
            $type = $type->target();
        }
        if ($type !== null && (!$type instanceof NameType || !in_array($type->value, ['Sig', 'DocTimeStamp'], true))) {
            return false;
        }
        // Type is optional in a signature dictionary. The writer recognizes the
        // integer offset/length pairs and required named security Filter.
        $range = $dictionary->get('ByteRange');
        $filter = $dictionary->get('Filter');
        if (!$range instanceof ArrayType || count($range->items) < 2 || count($range->items) % 2 !== 0 || !$filter instanceof NameType) {
            return false;
        }
        foreach ($range->items as $part) {
            if (!$part instanceof IntegerType || $part->value < 0) {
                return false;
            }
        }
        return true;
    }

    /** Embedded object and content-stream strings are encrypted with their containing stream. */
    public function serializeStreamValue(BasicType|ContentStreams $object): string
    {
        ++$this->encryptionSuppression;
        try {
            if ($object instanceof ContentStreams) {
                if ((new \ReflectionMethod($object, 'toPdf'))->getDeclaringClass()->getName() !== ContentStreams::class) {
                    // Caller-defined byte output has no native emission evidence without parsing it.
                    return $object->toPdf($this);
                }
                $facts = new AssociatedFilesMarkedContentFacts($object);
                $metadataFacts = new MetadataMarkedContentFacts($facts->content);
                $bytes = $facts->content->toPdf($this);
                if ($this->containingIdentifier !== null) {
                    $this->nativeContentEmissions[(string) $this->containingIdentifier] = [$object, $facts, $metadataFacts];
                }
                return $bytes;
            }
            return $this->serialize($object);
        } finally {
            --$this->encryptionSuppression;
        }
    }

    /** Stream encoders run before encryption; xref and excluded metadata bytes stay clear. */
    /** @return list<Filter> */
    public function streamFilters(StreamObject $stream): array
    {
        if ($this->hasExternalFile($stream)) {
            return $stream->filters;
        }
        if ($stream === $this->metadataExemption?->stream && (new StreamCryptFilter())->explicit($stream) === null) {
            return [new Crypt(), ...$stream->filters];
        }
        return $this->encryption?->streamFilters($stream) ?? $stream->filters;
    }

    /** Stream encoders run before encryption; xref and excluded metadata bytes stay clear. */
    public function encryptStream(StreamObject $stream, string $bytes): string
    {
        if ($this->hasExternalFile($stream)) {
            return $bytes;
        }
        $explicit = (new StreamCryptFilter())->explicit($stream);
        if ($explicit !== null && $stream instanceof CrossReferenceStreamObject) {
            throw new \LogicException('Cross-reference streams cannot use a Crypt filter.');
        }
        if (!$this->encrypting() || $stream instanceof CrossReferenceStreamObject) {
            if ($explicit !== null && $explicit->value !== 'Identity' && $this->encryption === null) {
                throw new \LogicException('A named Crypt filter requires a file security operation.');
            }
            return $bytes;
        }
        if ($this->indirectValue === $this->metadataExemption?->stream) {
            if ($explicit !== null && $explicit->value !== 'Identity') {
                throw new \LogicException('Excluded document metadata cannot declare an encrypting Crypt filter.');
            }
            return $bytes;
        }
        $identifier = $this->containingIdentifier ?? $this->identifier($this->indirectValue ?? throw new \LogicException('An encrypted stream must belong to an indirect object.'));
        return $this->encryption?->encryptStream($bytes, $identifier, $stream) ?? $bytes;
    }

    private function hasExternalFile(StreamObject $stream): bool
    {
        $file = $stream->dictionary()->get('F');
        $file = $file instanceof IndirectReference ? $this->effectiveReferenceValue($file) : $file;
        if ($stream instanceof \PDF\Syntax\Object\ExternalStreamObject || ($file !== null && !$file instanceof NullType)) {
            (new \PDF\Document\Files\ExternalStreamsGraphValidation())->validateFile($file);
        }
        return $file !== null && !$file instanceof NullType;
    }

    private function encrypting(): bool
    {
        return $this->encryption !== null && $this->encryptionSuppression === 0 && $this->indirectValue !== null
            && !$this->indirectValue instanceof CrossReferenceStreamObject
            && $this->indirectValue !== $this->encryption->dictionary();
    }
    private function guarded(BasicType $object): string
    {
        if ($this->active->offsetExists($object)) {
            throw new \LogicException('A direct object cycle must use an indirect reference.');
        }
        $this->active->offsetSet($object);
        try {
            if ($object instanceof StringType && $this->encrypting()) {
                $identifier = $this->containingIdentifier ?? $this->identifier($this->indirectValue ?? throw new \LogicException('An encrypted string must belong to an indirect object.'));
                return '<' . strtoupper(bin2hex($this->encryption?->encryptString($object->value, $identifier) ?? $object->value)) . '>';
            }
            return $object->toPdf($this);
        } finally {
            $this->active->offsetUnset($object);
        }
    }
}
