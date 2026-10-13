<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

/** Canonical, unique explicit OCG identities; equal names do not merge groups. */
final class GroupCollection extends ArrayType
{
    /** @var list<OptionalContentGroup> */
    public readonly array $groups;

    public function __construct(OptionalContentGroup ...$groups)
    {
        $seen = [];
        foreach ($groups as $group) {
            if (isset($seen[spl_object_id($group)])) {
                throw new \InvalidArgumentException('Optional content group identities must be unique within a collection.');
            }
            $seen[spl_object_id($group)] = true;
        }
        $this->groups = array_values($groups);
        parent::__construct(...array_map(static fn (OptionalContentGroup $group): IndirectReference => new IndirectReference($group), $this->groups));
    }

    public function contains(OptionalContentGroup $group): bool
    {
        return in_array($group, $this->groups, true);
    }
}
