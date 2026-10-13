<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\ArrayType;

/** [xmin xmax ymin ymax c1min c1max ...], preserving coordinate and sample domains (tables 82–84). */
final class MeshDecode extends ArrayType
{
    /** @var non-empty-list<DecodeInterval> */
    public readonly array $components;

    public function __construct(public readonly DecodeInterval $x, public readonly DecodeInterval $y, DecodeInterval ...$components)
    {
        if ($components === []) {
            throw new \InvalidArgumentException('A mesh Decode array needs at least one explicit component interval.');
        }
        $this->components = array_values($components);
        $values = [$x->first, $x->last, $y->first, $y->last];
        foreach ($components as $component) {
            $values[] = $component->first;
            $values[] = $component->last;
        }
        parent::__construct(...$values);
    }
}
