<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

/** A labelled collection or an unlabelled hierarchy of actual sublayers. */
final readonly class OrderBranch
{
    /** @var list<OptionalContentGroup|OrderBranch> */
    public array $items;

    public function __construct(public ?TextString $label, OptionalContentGroup|OrderBranch ...$items)
    {
        $this->items = array_values($items);
    }

    public function value(): ArrayType
    {
        $items = $this->label === null ? [] : [$this->label];
        foreach ($this->items as $item) {
            $items[] = $item instanceof OptionalContentGroup ? new IndirectReference($item) : $item->value();
        }
        return new ArrayType(...$items);
    }

    public function groups(): GroupCollection
    {
        $groups = [];
        foreach ($this->items as $item) {
            foreach (($item instanceof OptionalContentGroup ? [$item] : $item->groups()->groups) as $group) {
                $groups[spl_object_id($group)] = $group;
            }
        }
        return new GroupCollection(...array_values($groups));
    }
}
