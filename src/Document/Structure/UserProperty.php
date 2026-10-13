<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\Definition\UserPropertyDictionaryTable329Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** A user property is data, with an explicit display representation and visibility (page 577). */
final class UserProperty extends SchemaDictionary
{
    public const string TABLE = '329';

    public function __construct(StringType $name, BasicType $value, ?StringType $formatted = null, BooleanType $hidden = new BooleanType(false))
    {
        $entries = [new Entry(new NameType('N'), $name), new Entry(new NameType('V'), $value), new Entry(new NameType('H'), $hidden)];
        if ($formatted !== null) {
            $entries[] = new Entry(new NameType('F'), $formatted);
        }
        parent::__construct(new UserPropertyDictionaryTable329Definition(), ...$entries);
    }

    public function without(NameType|string $key): static
    {
        if (in_array($key instanceof NameType ? $key->value : $key, ['N', 'V'], true)) {
            throw new \InvalidArgumentException('A user property requires its name and value.');
        }
        return parent::without($key);
    }
}
