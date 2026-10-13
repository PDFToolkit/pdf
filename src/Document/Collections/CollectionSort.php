<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\CollectionSortDictionaryTable158Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Table158/page381: broadcast boolean, missing directions=true, extra directions ignored. */
final class CollectionSort extends SchemaDictionary implements VersionedComponent
{
    use CollectionVersion;
    public const string TABLE = '158';

    public function __construct(CollectionSchema $schema, public readonly NameType|CollectionSortFields $fields, public readonly BooleanType|CollectionSortDirections $ascending = new BooleanType(true))
    {
        foreach ($fields instanceof NameType ? [$fields] : $fields->items as $field) {
            if (!$field instanceof NameType) {
                throw new \LogicException('CollectionSortFields contains names only.');
            }
            $schema->field($field);
        }
        parent::__construct(new CollectionSortDictionaryTable158Definition(), new Entry(new NameType('Type'), new NameType('CollectionSort')), new Entry(new NameType('S'), $fields), new Entry(new NameType('A'), $ascending));
    }

    public function ascendingAt(int $index): bool
    {
        $count = $this->fields instanceof NameType ? 1 : count($this->fields);
        if ($index < 0 || $index >= $count) {
            throw new \OutOfBoundsException('The sort index must identify an actual S field.');
        }
        if ($this->ascending instanceof BooleanType) {
            return $this->ascending->value;
        }
        $direction = $this->ascending->items[$index] ?? null;
        return $direction instanceof BooleanType ? $direction->value : true;
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Collection sorts require explicit schema fields and directions.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Collection sorts are constructor-controlled.');
    }
}
