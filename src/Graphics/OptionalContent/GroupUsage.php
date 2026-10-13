<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Specification\Definition\OptionalContentUsageDictionaryTable102Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class GroupUsage extends SchemaDictionary
{
    public const string TABLE = '102';
    /** @var array<string, UsageEntry> */
    private readonly array $categories;

    public function __construct(UsageEntry ...$categories)
    {
        $map = [];
        $entries = [];
        foreach ($categories as $category) {
            if (isset($map[$category->category->value])) {
                throw new \InvalidArgumentException('Each usage category may be declared once per OCG.');
            }
            $map[$category->category->value] = $category;
            $entries[] = new Entry(new NameType($category->category->value), $category);
        }
        $this->categories = $map;
        parent::__construct(new OptionalContentUsageDictionaryTable102Definition(), ...$entries);
    }

    public function category(UsageCategory $category): ?UsageEntry
    {
        return $this->categories[$category->value] ?? null;
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Usage categories are explicit constructor objects.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Usage categories are explicit constructor objects.');
    }
}
