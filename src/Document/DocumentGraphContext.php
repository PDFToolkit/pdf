<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\RevisionValueSnapshot;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** A read-only view: resolving references never registers objects or reparses mutable historical builders. */
final class DocumentGraphContext
{
    /** @var array<int, RevisionObject> */
    private array $records = [];
    private readonly RevisionValueSnapshot $capture;
    public readonly DictionaryType $catalog;
    /** @var \SplObjectStorage<BasicType, null>|null */
    private ?\SplObjectStorage $reachableValues = null;
    /** @var array<string, true> */
    private array $reachableIdentifiers = [];

    public function __construct(private readonly SerializationContext $context, DictionaryType $catalog, RevisionObject ...$effective)
    {
        $this->capture = new RevisionValueSnapshot();
        foreach ($effective as $record) {
            $this->records[$record->identifier->objectNumber->value] = $record;
        }
        foreach ($context->objects() as $number => $value) {
            if (!isset($this->records[$number])) {
                $this->records[$number] = new RevisionObject($context->knownIdentifier($value) ?? throw new \LogicException('A prepared object needs its allocated identifier.'), $value);
            }
        }
        $frozen = $this->capture->capture($catalog);
        $this->catalog = $frozen instanceof DictionaryType ? $frozen : throw new \LogicException('The catalog snapshot must remain a dictionary.');
    }

    public function identifier(BasicType $value): ?ObjectIdentifier
    {
        if (!$value instanceof IndirectReference) {
            return $this->context->knownIdentifier($value);
        }
        if ($this->context->knownIdentifier($value->target()) === null) {
            throw new \InvalidArgumentException('A document relationship references an object outside the prepared graph: ' . $value->target()::class . '.');
        }
        return $this->context->reference($value);
    }

    public function sameObject(BasicType $first, BasicType $second): bool
    {
        $a = $this->identifier($first);
        $b = $this->identifier($second);
        if ($a !== null && $b !== null) {
            return (string) $a === (string) $b;
        }
        if ($first === $second || $first instanceof StreamObject || $second instanceof StreamObject) {
            return $first === $second;
        }
        $one = $this->capture->capture($first);
        $two = $this->capture->capture($second);
        return $first === $two || $second === $one || $one === $two;
    }

    public function resolve(BasicType $value): BasicType
    {
        if ($value instanceof IndirectReference) {
            $identifier = $this->identifier($value);
            $record = $identifier === null ? null : ($this->records[$identifier->objectNumber->value] ?? null);
            return $record !== null && (string) $record->identifier === (string) $identifier ? $record->snapshot : new NullType();
        }
        // A direct value stays direct, even when that PHP instance also has a historical indirect identity.
        return $value instanceof StreamObject ? $value : $this->capture->capture($value);
    }

    public function dictionary(BasicType $value): ?DictionaryType
    {
        $identifier = $this->identifier($value);
        if ($identifier !== null) {
            $record = $this->records[$identifier->objectNumber->value] ?? null;
            return $record !== null && (string) $record->identifier === (string) $identifier ? $record->dictionary : null;
        }
        $dictionary = $value instanceof DictionaryType ? $value : ($value instanceof StreamObject ? $value->dictionary() : null);
        if ($dictionary === null) {
            return null;
        }
        $frozen = $this->capture->capture($dictionary);
        return $frozen instanceof DictionaryType ? $frozen : null;
    }

    /** Null and nonexistent generations are omitted entries under PDF7.3.9. */
    public function entry(DictionaryType $dictionary, NameType|string $key): ?BasicType
    {
        $value = $dictionary->get($key);
        return $value === null || $this->resolve($value) instanceof NullType ? null : $value;
    }

    public function isCurrent(BasicType $value): bool
    {
        if (!$this->context->isHistorical($value)) {
            return true;
        }
        $identifier = $this->context->knownIdentifier($value);
        return $identifier !== null && ($this->context->objects()[$identifier->objectNumber->value] ?? null) === $value;
    }

    /** Reachability is from the actual catalog, not from every historical xref record. */
    public function isReachable(BasicType $target): bool
    {
        if ($this->reachableValues === null) {
            $this->indexReachability();
        }
        $reachable = $this->reachableValues ?? throw new \LogicException('Reachability indexing must initialize its value storage.');
        $identifier = $this->identifier($target);
        if ($identifier !== null && isset($this->reachableIdentifiers[(string) $identifier])) {
            return true;
        }
        if ($reachable->offsetExists($target)) {
            return true;
        }
        return !$target instanceof StreamObject && $reachable->offsetExists($this->capture->capture($target));
    }

    private function indexReachability(): void
    {
        $this->reachableValues = new \SplObjectStorage();
        $queue = [$this->catalog];
        $seen = [];
        while ($queue !== []) {
            $value = array_pop($queue);
            $identifier = $this->identifier($value);
            $key = $identifier === null ? 'direct:' . spl_object_id($value) : (string) $identifier;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->reachableValues->offsetSet($value);
            if ($identifier !== null) {
                $this->reachableIdentifiers[(string) $identifier] = true;
            }
            $resolved = $this->resolve($value);
            $dictionary = $this->dictionary($value);
            if ($dictionary !== null) {
                foreach ($dictionary->entries() as $entry) {
                    $queue[] = $entry->value;
                }
            } elseif ($resolved instanceof ArrayType) {
                array_push($queue, ...$resolved->items);
            }
            if (count($seen) > 1000000) {
                throw new \LengthException('Graph reachability exceeds its explicit one-million-node construction limit.');
            }
        }
    }

    /**
     * Flatten an existing name tree, retaining byte keys and effective reference values.
     * @return array<string, BasicType>
     */
    public function nameTree(BasicType $root): array
    {
        $queue = [[$root, true]];
        $seen = [];
        $pairs = [];
        $previous = null;
        while ($queue !== []) {
            [$node, $isRoot] = array_pop($queue);
            $identifier = $this->identifier($node);
            $key = $identifier === null ? 'direct:' . spl_object_id($node) : (string) $identifier;
            if (isset($seen[$key])) {
                throw new \LogicException('A name tree cannot repeat a child or contain a cycle.');
            }
            $seen[$key] = true;
            $dictionary = $this->dictionary($node);
            if ($dictionary === null) {
                throw new \InvalidArgumentException('A name-tree node must resolve to a dictionary.');
            }
            $names = $this->entry($dictionary, 'Names');
            $kids = $this->entry($dictionary, 'Kids');
            $limits = $this->nameLimits($dictionary, !$isRoot);
            if ($isRoot && $limits !== null) {
                throw new \InvalidArgumentException('A name-tree root must omit Limits.');
            }
            if (($names === null) === ($kids === null)) {
                throw new \InvalidArgumentException('A name-tree node requires exactly one of Names or Kids.');
            }
            if ($names !== null) {
                $values = $this->resolve($names);
                if (!$values instanceof ArrayType || count($values) % 2 !== 0) {
                    throw new \InvalidArgumentException('Name-tree leaf values must be string/value pairs.');
                }
                for ($i = 0; $i < count($values); $i += 2) {
                    $name = $this->resolve($values->items[$i]);
                    if (!$name instanceof StringType || isset($pairs[bin2hex($name->value)]) || ($previous !== null && strcmp($previous, $name->value) >= 0)) {
                        throw new \InvalidArgumentException('Name-tree keys must be unique byte strings in ascending byte order.');
                    }
                    $pairs[bin2hex($name->value)] = $values->items[$i + 1];
                    $previous = $name->value;
                }
                $first = count($values) === 0 ? null : $this->resolve($values->items[0]);
                $last = count($values) === 0 ? null : $this->resolve($values->items[count($values) - 2]);
                if ($limits !== null && (!$first instanceof StringType || !$last instanceof StringType || $limits !== [$first->value, $last->value])) {
                    throw new \InvalidArgumentException('Name-tree leaf Limits must match its actual first and last byte keys.');
                }
            } else {
                $children = $this->resolve($kids ?? throw new \LogicException('Missing name-tree children.'));
                if (!$children instanceof ArrayType) {
                    throw new \InvalidArgumentException('Name-tree Kids must resolve to an array.');
                }
                $ranges = [];
                foreach ($children as $child) {
                    if (!$child instanceof IndirectReference) {
                        throw new \InvalidArgumentException('Name-tree children must be indirect references.');
                    }
                    $childDictionary = $this->dictionary($child) ?? throw new \InvalidArgumentException('Name-tree children must resolve to dictionaries.');
                    $range = $this->nameLimits($childDictionary, true) ?? throw new \LogicException('A non-root name-tree child has required Limits.');
                    if ($ranges !== [] && strcmp($ranges[array_key_last($ranges)][1], $range[0]) >= 0) {
                        throw new \InvalidArgumentException('Name-tree child ranges must be disjoint and ascend in byte order.');
                    }
                    $ranges[] = $range;
                }
                if ($ranges === [] || ($limits !== null && $limits !== [$ranges[0][0], $ranges[array_key_last($ranges)][1]])) {
                    throw new \InvalidArgumentException('Name-tree branch Limits must encompass its actual child ranges.');
                }
                foreach (array_reverse($children->items) as $child) {
                    $queue[] = [$child, false];
                }
            }
            if (count($seen) > 1000000) {
                throw new \LengthException('Name-tree validation exceeds its explicit one-million-node construction limit.');
            }
        }
        return $pairs;
    }

    /** @return array{string, string}|null */
    private function nameLimits(DictionaryType $dictionary, bool $required): ?array
    {
        $value = $this->entry($dictionary, 'Limits');
        if ($value === null) {
            if ($required) {
                throw new \InvalidArgumentException('A non-root name-tree node requires Limits.');
            }
            return null;
        }
        $limits = $this->resolve($value);
        if (!$limits instanceof ArrayType || count($limits) !== 2) {
            throw new \InvalidArgumentException('Name-tree Limits requires two string keys.');
        }
        $first = $this->resolve($limits->items[0]);
        $last = $this->resolve($limits->items[1]);
        if (!$first instanceof StringType || !$last instanceof StringType || strcmp($first->value, $last->value) > 0) {
            throw new \InvalidArgumentException('Name-tree Limits must be ascending byte strings.');
        }
        return [$first->value, $last->value];
    }
}
