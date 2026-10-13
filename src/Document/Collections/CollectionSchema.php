<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\CollectionSchemaDictionaryTable156Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class CollectionSchema extends SchemaDictionary implements VersionedComponent
{
    use CollectionVersion;
    public const string TABLE = '156';
    /** @var array<string, CollectionField> */
    private readonly array $fields;

    public function __construct(CollectionSchemaField ...$fields)
    {
        $definitions = [];
        $entries = [new Entry(new NameType('Type'), new NameType('CollectionSchema'))];
        foreach ($fields as $field) {
            if (isset($definitions[$field->name->value])) {
                throw new \InvalidArgumentException('Collection-schema field names must be unique.');
            }
            $definitions[$field->name->value] = $field->field;
            $entries[] = new Entry($field->name, $field->field);
        }
        $this->fields = $definitions;
        parent::__construct(new CollectionSchemaDictionaryTable156Definition(), ...$entries);
    }

    public function field(NameType $name): CollectionField
    {
        return $this->fields[$name->value] ?? throw new \InvalidArgumentException('A collection item or sort references a field absent from its schema.');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Collection-schema fields require explicit name and field objects.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Collection schemas are constructor-controlled.');
    }
}
