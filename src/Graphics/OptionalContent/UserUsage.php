<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class UserUsage extends UsageEntry
{
    /** @var non-empty-list<TextString> */
    public readonly array $names;

    public function __construct(public readonly UserType $type, TextString ...$names)
    {
        if ($names === []) {
            throw new \InvalidArgumentException('User usage requires at least one named individual, title or organization.');
        }
        $this->names = array_values($names);
        parent::__construct(UsageCategory::User, new Entry(new NameType('Type'), new NameType($type->value)), new Entry(new NameType('Name'), count($names) === 1 ? $names[0] : new ArrayType(...$names)));
    }
}
