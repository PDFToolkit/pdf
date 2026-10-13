<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;
use PDF\Syntax\Object\DictionaryObject\Entry;

/**
 * 7.3.7. Object-valued entries; with() returns a new dictionary.
 * @implements \IteratorAggregate<string, Entry>
 */
class DictionaryType implements BasicType, \Countable, \IteratorAggregate
{
    use SerializesPdf;
    /** @var array<string, Entry> */
    private array $storedEntries = [];

    public function __construct(Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (isset($this->storedEntries[$entry->key->value])) {
                throw new \InvalidArgumentException('Duplicate dictionary key: ' . $entry->key->value);
            }
            $this->storedEntries[$entry->key->value] = $entry;
        }
    }

    /** @return iterable<Entry> */
    public function entries(): iterable
    {
        yield from $this->storedEntries;
    }
    public function get(NameType|string $key): ?BasicType
    {
        $key = $key instanceof NameType ? $key->value : $key;
        foreach ($this->entries() as $entry) {
            if ($entry->key->value === $key) {
                return $entry->value;
            }
        }
        return null;
    }
    public function has(NameType|string $key): bool
    {
        return $this->get($key) !== null;
    }

    #[\NoDiscard]
    public function with(Entry $entry): static
    {
        $copy = clone $this;
        $copy->storedEntries[$entry->key->value] = $entry;
        return $copy;
    }

    #[\NoDiscard]
    public function without(NameType|string $key): static
    {
        $copy = clone $this;
        unset($copy->storedEntries[$key instanceof NameType ? $key->value : $key]);
        return $copy;
    }
    public function count(): int
    {
        return iterator_count($this->getIterator());
    }
    public function getIterator(): \Traversable
    {
        yield from $this->entries();
    }
    public function toPdf(SerializationContext $context): string
    {
        $pairs = [];
        $keys = [];
        foreach ($this->entries() as $entry) {
            if (isset($keys[$entry->key->value])) {
                throw new \LogicException('Duplicate dictionary key: ' . $entry->key->value);
            }
            $keys[$entry->key->value] = true;
            $pairs[] = $context->serializeEntry($this, $entry);
        }
        return '<<' . ($pairs === [] ? '' : ' ' . implode(' ', $pairs) . ' ') . '>>';
    }
}
