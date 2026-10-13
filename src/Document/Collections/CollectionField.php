<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\CollectionFieldDictionaryTable157Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** Table157/page380: native data type and presentation facts for one collection field. */
final class CollectionField extends SchemaDictionary implements VersionedComponent
{
    use CollectionVersion;
    public const string TABLE = '157';

    public function __construct(public readonly CollectionFieldType $type, TextString $displayName, ?IntegerType $order = null, BooleanType $visible = new BooleanType(true), BooleanType $editable = new BooleanType(false))
    {
        $entries = [new Entry(new NameType('Type'), new NameType('CollectionField')), new Entry(new NameType('Subtype'), new NameType($type->value)), new Entry(new NameType('N'), $displayName), new Entry(new NameType('V'), $visible), new Entry(new NameType('E'), $editable)];
        if ($order !== null) {
            $entries[] = new Entry(new NameType('O'), $order);
        }
        parent::__construct(new CollectionFieldDictionaryTable157Definition(), ...$entries);
    }

    public function accepts(BasicType $value): bool
    {
        return match ($this->type) {
            CollectionFieldType::Number, CollectionFieldType::FileSize => $value instanceof NumericType,
            CollectionFieldType::Date, CollectionFieldType::ModificationDate, CollectionFieldType::CreationDate => $value instanceof Date,
            default => $value instanceof StringType,
        };
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Collection fields require explicit type and presentation constructor arguments.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Collection-field requirements are constructor-controlled.');
    }
}
