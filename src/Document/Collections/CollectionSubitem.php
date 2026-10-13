<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\CollectionSubitemDictionaryTable49Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Table49/page115: a display prefix never becomes part of the sortable data. */
final class CollectionSubitem extends SchemaDictionary implements VersionedComponent
{
    use CollectionVersion;
    public const string TABLE = '49';

    public function __construct(public readonly TextString|Date|NumericType|null $data = null, public readonly ?TextString $prefix = null)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('CollectionSubitem'))];
        if ($data !== null) {
            $entries[] = new Entry(new NameType('D'), $data);
        }
        if ($prefix !== null) {
            $entries[] = new Entry(new NameType('P'), $prefix);
        }
        parent::__construct(new CollectionSubitemDictionaryTable49Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Collection subitems require explicit data and prefix arguments.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Collection-subitem data is constructor-controlled.');
    }
}
