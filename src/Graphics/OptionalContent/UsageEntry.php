<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

abstract class UsageEntry extends DictionaryType
{
    protected function __construct(public readonly UsageCategory $category, Entry ...$entries)
    {
        parent::__construct(...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Usage category entries are constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Usage category entries are constructor-controlled.');
    }
}
