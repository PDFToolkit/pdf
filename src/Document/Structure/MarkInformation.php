<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\Definition\MarkInformationDictionaryTable321Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Explicit conformance statement, user-property presence and suspect flag (PDF page 564). */
final class MarkInformation extends SchemaDictionary
{
    public const string TABLE = '321';

    public function __construct(
        public readonly BooleanType $marked,
        public readonly BooleanType $userProperties = new BooleanType(false),
        public readonly BooleanType $suspects = new BooleanType(false),
    ) {
        parent::__construct(
            new MarkInformationDictionaryTable321Definition(),
            new Entry(new NameType('Marked'), $marked),
            new Entry(new NameType('UserProperties'), $userProperties),
            new Entry(new NameType('Suspects'), $suspects),
        );
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Configure mark information explicitly through its constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Build new mark information to change its flags.');
    }
}
