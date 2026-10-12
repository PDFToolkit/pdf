<?php

declare(strict_types=1);

namespace PDF\Serialization;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\ObjectStream;
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

    public function __construct()
    {
        $this->identifiers = new \SplObjectStorage();
        $this->active = new \SplObjectStorage();
    }

    /** Discover explicit identifiers before assigning automatic numbers. */
    public function prepare(BasicType|IndirectObject ...$roots): void
    {
        $seen = [];
        $targets = [];
        $visit = function (BasicType $value) use (&$visit, &$seen, &$targets): void {
            if ($value instanceof IndirectReference) {
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
                    $this->register($member->value, $member->identifier());
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
                $this->register($root->value, $root->identifier);
                $root = $root->value;
            }
            if (!$root instanceof IndirectReference) {
                $targets[spl_object_id($root)] = $root;
            }
            $visit($root);
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
                ++$this->nextNumber;
            }
            $identifier = new ObjectIdentifier(new ObjectNumber($this->nextNumber++));
        }
        $number = $identifier->objectNumber->value;
        if (isset($this->objects[$number])) {
            throw new \LogicException('Duplicate indirect object number: ' . $number);
        }
        $this->identifiers[$object] = $identifier;
        $this->objects[$number] = $object;
        return $identifier;
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
