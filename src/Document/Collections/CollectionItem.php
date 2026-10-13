<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\CollectionItemDictionaryTable48Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Tables48–49/pages114–115: validate each supplied item against its explicitly supplied schema. */
final class CollectionItem extends SchemaDictionary implements VersionedComponent
{
    use CollectionVersion;
    public const string TABLE = '48';

    public function __construct(CollectionSchema $schema, CollectionItemValue ...$values)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('CollectionItem'))];
        foreach ($values as $value) {
            $data = $value->value instanceof CollectionSubitem ? $value->value->data : $value->value;
            if ($data !== null && !$schema->field($value->field)->accepts($data)) {
                throw new \InvalidArgumentException('A collection-item value must have the data type identified by its schema field.');
            }
            $schema->field($value->field);
            $entries[] = new Entry($value->field, $value->value);
        }
        parent::__construct(new CollectionItemDictionaryTable48Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Collection items require values coupled to their explicit schema.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Collection items are constructor-controlled.');
    }
}
