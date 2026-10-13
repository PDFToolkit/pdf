<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;

/** Table 117 W array, preserving the explicitly declared CIDs and PDF widths. */
final class CIDWidths extends ArrayType
{
    public function __construct(public readonly CharacterMap $map)
    {
        $items = [];
        $start = 0;
        $last = 0;
        $widths = [$map->notdefWidth];
        foreach ($map->mappings as $mapping) {
            if ($mapping->cid !== $last + 1) {
                array_push($items, new IntegerType($start), new ArrayType(...$widths));
                $start = $mapping->cid;
                $widths = [];
            }
            $widths[] = $mapping->width;
            $last = $mapping->cid;
        }
        array_push($items, new IntegerType($start), new ArrayType(...$widths));
        parent::__construct(...$items);
    }
}
