<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

/**
 * Rectangle is used to describe locations on a page and bounding boxes for a
 * variety of objects.
 * A rectangle shall be written as an array of four numbers giving the
 * coordinates of a pair of diagonally opposite corners.
 *
 * https://www.adobe.com/content/dam/acom/en/devnet/acrobat/pdfs/PDF32000_2008.pdf#G6.1840003
 */
class Rectangle
{
    /** @var float */
    private float $lowerLeftX;

    /** @var float */
    private float $lowerLeftY;

    /** @var float */
    private float $upperRightX;

    /** @var float */
    private float $upperRightY;

    public function __construct(float $lowerLeftX, float $lowerLeftY, float $upperRightX, float $upperRightY)
    {
        $this->lowerLeftX = $lowerLeftX;
        $this->lowerLeftY = $lowerLeftY;
        $this->upperRightX = $upperRightX;
        $this->upperRightY = $upperRightY;
    }

    /**
     * Although rectangles are conventionally specified by their lower-left and upper-right corners
     */
    public function __toString(): string
    {
        return sprintf('[%s %s %s %s]', $this->lowerLeftX, $this->lowerLeftY, $this->upperRightX, $this->upperRightY);
    }
}
