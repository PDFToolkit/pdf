<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

final class GroupOrder extends ArrayType
{
    private readonly OrderBranch $branch;

    public function __construct(OptionalContentGroup|OrderBranch ...$items)
    {
        $this->branch = new OrderBranch(null, ...$items);
        parent::__construct(...array_map(static fn (OptionalContentGroup|OrderBranch $item): IndirectReference|ArrayType => $item instanceof OptionalContentGroup ? new IndirectReference($item) : $item->value(), $items));
    }

    public function groups(): GroupCollection
    {
        return $this->branch->groups();
    }
}
