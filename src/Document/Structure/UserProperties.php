<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\Definition\AttributeObjectDictionaryForUserPropertiesTable328Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Ordered properties; their presence requires MarkInfo.UserProperties=true (page 577). */
final class UserProperties extends SchemaDictionary
{
    public const string TABLE = '328';

    public function __construct(UserProperty ...$properties)
    {
        parent::__construct(
            new AttributeObjectDictionaryForUserPropertiesTable328Definition(),
            new Entry(new NameType('O'), new NameType('UserProperties')),
            new Entry(new NameType('P'), new ArrayType(...$properties)),
        );
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Build user properties from explicit UserProperty objects.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('User properties require both their owner and property array.');
    }
}
