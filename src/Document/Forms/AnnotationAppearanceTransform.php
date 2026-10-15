<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\CoordinateSystems\Point;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\NumericType;

/**
 * Appearance placement, ISO 32000-1 12.5.5 (physical PDF page394).
 * Adobe PDF1.7 errata physical page10 corrects Algorithm8.1 to AA = Matrix × A.
 */
final readonly class AnnotationAppearanceTransform
{
    public Rectangle $transformedBoundingBox;
    public Matrix $fittingMatrix;
    public Matrix $effectiveMatrix;

    public function __construct(public Rectangle $boundingBox, public Rectangle $annotationRectangle, public Matrix $appearanceMatrix = new Matrix())
    {
        $corners = [];
        foreach ([$boundingBox->lowerLeftX, $boundingBox->upperRightX] as $x) {
            foreach ([$boundingBox->lowerLeftY, $boundingBox->upperRightY] as $y) {
                $corners[] = $appearanceMatrix->transform(new Point($x, $y));
            }
        }
        $xs = array_map(fn (Point $point): int|float => $point->x->value, $corners);
        $ys = array_map(fn (Point $point): int|float => $point->y->value, $corners);
        $minimumX = min($xs);
        $minimumY = min($ys);
        $width = max($xs) - $minimumX;
        $height = max($ys) - $minimumY;
        if ($width == 0 || $height == 0) {
            throw new \InvalidArgumentException('A degenerate transformed appearance bounding box has no fitting transform.');
        }
        $this->transformedBoundingBox = new Rectangle(new NumericType($minimumX), new NumericType($minimumY), new NumericType(max($xs)), new NumericType(max($ys)));
        // Rectangles permit reversed corners. Placement uses their normalized extent.
        $left = min($annotationRectangle->lowerLeftX->value, $annotationRectangle->upperRightX->value);
        $bottom = min($annotationRectangle->lowerLeftY->value, $annotationRectangle->upperRightY->value);
        $scaleX = abs($annotationRectangle->upperRightX->value - $annotationRectangle->lowerLeftX->value) / $width;
        $scaleY = abs($annotationRectangle->upperRightY->value - $annotationRectangle->lowerLeftY->value) / $height;
        $this->fittingMatrix = new Matrix(new NumericType($scaleX), new NumericType(0), new NumericType(0), new NumericType($scaleY), new NumericType($left - $minimumX * $scaleX), new NumericType($bottom - $minimumY * $scaleY));
        $this->effectiveMatrix = $appearanceMatrix->then($this->fittingMatrix);
    }
}
