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

    public function __construct(ObjectNumber $firstNewNumber = new ObjectNumber(), RevisionObject ...$existingObjects)
    {
        $this->identifiers = new \SplObjectStorage();
        $this->active = new \SplObjectStorage();
        $this->historical = new \SplObjectStorage();
        $this->firstNewNumber = $firstNewNumber->value;
        $this->nextNumber = $this->firstNewNumber;
        foreach ($existingObjects as $record) {
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
        return $this->guarded($object);
    }
    private function guarded(BasicType $object): string
    {
        if ($this->active->offsetExists($object)) {
            throw new \LogicException('A direct object cycle must use an indirect reference.');
        }
        $this->active->offsetSet($object);
        try {
            return $object->toPdf($this);
        } finally {
            $this->active->offsetUnset($object);
        }
    }
}
