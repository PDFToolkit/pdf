<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Graphics\CoordinateSystems\Point;
use PDF\Syntax\Object\ArrayType;

/** 12.9.1: the last containing viewport owns a measurement's first point. */
final class Viewports extends ArrayType
{
    /** @var list<Viewport> */
    public readonly array $viewports;

    public function __construct(Viewport ...$viewports)
    {
        $this->viewports = array_values($viewports);
        parent::__construct(...$viewports);
    }

    public function forPoint(Point $first): ?Viewport
    {
        foreach (array_reverse($this->viewports) as $viewport) {
            if ($viewport->contains($first)) {
                return $viewport;
            }
        }
        return null;
    }
}
