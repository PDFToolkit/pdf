<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\NumericType;

/** Explicit page display bounds and optional content bounds; content is never inferred. */
final readonly class DestinationGeometry
{
    public function __construct(public Rectangle $pageBox, public ?Rectangle $contentBounds = null)
    {
    }

    /** Smallest upright content box, restricted to CropBox, ISO12.3.2.2 p374. */
    public function boundingBox(): Rectangle
    {
        $content = $this->contentBounds ?? throw new \LogicException('FitB needs explicitly supplied content bounds.');
        $left = max(min($content->lowerLeftX->value, $content->upperRightX->value), min($this->pageBox->lowerLeftX->value, $this->pageBox->upperRightX->value));
        $bottom = max(min($content->lowerLeftY->value, $content->upperRightY->value), min($this->pageBox->lowerLeftY->value, $this->pageBox->upperRightY->value));
        $right = min(max($content->lowerLeftX->value, $content->upperRightX->value), max($this->pageBox->lowerLeftX->value, $this->pageBox->upperRightX->value));
        $top = min(max($content->lowerLeftY->value, $content->upperRightY->value), max($this->pageBox->lowerLeftY->value, $this->pageBox->upperRightY->value));
        if ($right <= $left || $top <= $bottom) {
            throw new \LogicException('The cropped content has no positive bounding box to fit.');
        }
        return new Rectangle(new NumericType($left), new NumericType($bottom), new NumericType($right), new NumericType($top));
    }
}
