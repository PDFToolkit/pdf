<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\FileStructure\CrossReferenceStream;
use PDF\Security\HistoricalRevision5Encryption;
use PDF\Security\DocumentMetadataExemption;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

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
    private readonly int $firstNewNumber;
    private readonly ?HistoricalRevision5Encryption $encryption;
    private readonly ?DocumentMetadataExemption $metadataExemption;
    private ?BasicType $indirectValue = null;
    private int $encryptionSuppression = 0;

    public function __construct(ObjectNumber $firstNewNumber = new ObjectNumber(), RevisionObject|HistoricalRevision5Encryption|DocumentMetadataExemption ...$existingObjects)
    {
        $this->identifiers = new \SplObjectStorage();
        $this->active = new \SplObjectStorage();
        $this->historical = new \SplObjectStorage();
        $this->firstNewNumber = $firstNewNumber->value;
        $this->nextNumber = $this->firstNewNumber;
        $encryption = null;
        $metadata = null;
        foreach ($existingObjects as $record) {
            if ($record instanceof DocumentMetadataExemption) {
                if ($metadata !== null) {
                    throw new \InvalidArgumentException('Only one document-level metadata stream can be exempted.');
                }
                $metadata = $record;
                continue;
            }
            if ($record instanceof HistoricalRevision5Encryption) {
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
            foreach ($record->aliases as $alias) {
                if ($this->identifiers->offsetExists($alias)) {
                    throw new \InvalidArgumentException('Existing identities must have a single identifier.');
                }
                $this->identifiers[$alias] = $record->identifier;
                $this->historical->offsetSet($alias);
            }
        }
        if ($encryption !== null && $this->reservedIdentifiers !== []) {
            throw new \LogicException('Encrypted incremental serialization is not implemented.');
        }
        if ($metadata !== null && ($encryption === null || $encryption->dictionary->encryptMetadata->value)) {
            throw new \LogicException('A document metadata exemption requires EncryptMetadata=false.');
        }
        $this->encryption = $encryption;
        $this->metadataExemption = $metadata;
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
        $seen = [];
        $targets = [];
        $visit = function (BasicType $value) use (&$visit, &$seen, &$targets): void {
            if ($value instanceof IndirectReference) {
                if ($this->historical->offsetExists($value->target())) {
                    $this->reference($value);
                    return;
                }
                $targets[spl_object_id($value->target())] = $value->target();
                if ($value->identifier !== null) {
                    $this->register($value->target(), $value->identifier);
                }
                $visit($value->target());
                return;
            }
            if (isset($seen[spl_object_id($value)])) {
                return;
            }
            $seen[spl_object_id($value)] = true;
            if ($value instanceof ObjectStream) {
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
                $this->stage($root);
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

    public function isHistorical(BasicType $object): bool
    {
        return $this->historical->offsetExists($object);
    }

    public function reference(IndirectReference $reference): ObjectIdentifier
    {
        return $this->register($reference->target(), $reference->identifier);
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
        return $this->register($object);
    }

    public function serialize(BasicType $object): string
    {
        if ($object instanceof StreamObject) {
            throw new \LogicException('Streams must be indirect objects (7.3.8).');
        }
        return $this->guarded($object);
    }
    public function serializeIndirect(BasicType $object): string
    {
        $previous = $this->indirectValue;
        $this->indirectValue = $object;
        try {
            return $this->guarded($object);
        } finally {
            $this->indirectValue = $previous;
        }
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
            return $object instanceof ContentStreams ? $object->toPdf($this) : $this->serialize($object);
        } finally {
            --$this->encryptionSuppression;
        }
    }

    /** Stream encoders run before encryption; xref and excluded metadata bytes stay clear. */
    public function encryptStream(StreamObject $stream, string $bytes): string
    {
        if (!$this->encrypting() || $stream instanceof CrossReferenceStream) {
            return $bytes;
        }
        foreach ($stream->filters as $filter) {
            if ($filter->name()->value === 'Crypt') {
                throw new \LogicException('Explicit stream Crypt filters are not supported by the historical R=5 writer.');
            }
        }
        if ($this->indirectValue === $this->metadataExemption?->stream) {
            return $bytes;
        }
        return $this->encryption?->encrypt($bytes) ?? $bytes;
    }

    private function encrypting(): bool
    {
        return $this->encryption !== null && $this->encryptionSuppression === 0 && $this->indirectValue !== null
            && !$this->indirectValue instanceof CrossReferenceStream
            && $this->indirectValue !== $this->encryption->dictionary->dictionary();
    }
    private function guarded(BasicType $object): string
    {
        if ($this->active->offsetExists($object)) {
            throw new \LogicException('A direct object cycle must use an indirect reference.');
        }
        $this->active->offsetSet($object);
        try {
            if ($object instanceof StringType && $this->encrypting()) {
                return '<' . strtoupper(bin2hex($this->encryption?->encrypt($object->value) ?? $object->value)) . '>';
            }
            return $object->toPdf($this);
        } finally {
            $this->active->offsetUnset($object);
        }
    }
}
