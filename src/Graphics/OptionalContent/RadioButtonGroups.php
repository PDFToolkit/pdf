<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\ArrayType;

/** Explicit radio collections, permitting the prescribed all-OFF state. */
final class RadioButtonGroups extends ArrayType
{
    /** @var list<GroupCollection> */
    public readonly array $collections;

    public function __construct(GroupCollection ...$collections)
    {
        $this->collections = array_values($collections);
        parent::__construct(...$collections);
    }

    public function groups(): GroupCollection
    {
        $groups = [];
        foreach ($this->collections as $collection) {
            foreach ($collection->groups as $group) {
                $groups[spl_object_id($group)] = $group;
            }
        }
        return new GroupCollection(...array_values($groups));
    }
}
