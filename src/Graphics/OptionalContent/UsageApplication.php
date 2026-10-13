<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Specification\Definition\UsageApplicationDictionaryTable103Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class UsageApplication extends SchemaDictionary
{
    public const string TABLE = '103';
    /** @var list<UsageCategory> */
    public readonly array $categories;

    public function __construct(public readonly UsageEvent $event, public readonly GroupCollection $groups = new GroupCollection(), UsageCategory ...$categories)
    {
        $this->categories = array_values($categories);
        parent::__construct(new UsageApplicationDictionaryTable103Definition(), new Entry(new NameType('Event'), new NameType($event->value)), new Entry(new NameType('OCGs'), $groups), new Entry(new NameType('Category'), new ArrayType(...array_map(static fn (UsageCategory $category): NameType => new NameType($category->value), $categories))));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Usage application event, scope and categories are coupled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Usage application event, scope and categories are coupled.');
    }
}
