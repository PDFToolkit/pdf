<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AnnotationAppearanceTransform;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\CoordinateSystems\Point;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\TestCase;

final class AnnotationAppearanceTransformTest extends TestCase
{
    public function testCorrectedAppearanceMatrixOrderPlacesTheTransformedBoundsInsideRect(): void
    {
        $matrix = $this->matrix([0, 2, -3, 0, 7, 11]);
        $placement = new AnnotationAppearanceTransform($this->rectangle([1, 2, 5, 6]), $this->rectangle([20, 30, 140, 110]), $matrix);
        self::assertEquals([-11, 13, 1, 21], array_map(fn (NumericType $number): int|float => $number->value, $placement->transformedBoundingBox->items));
        // Analytic rotation: M × A = [0 20 -30 0 200 10].
        self::assertEquals([0, 20, -30, 0, 200, 10], array_map(fn (NumericType $number): int|float => $number->value, $placement->effectiveMatrix->operands()));
        $point = $placement->effectiveMatrix->transform($this->point(1, 2));
        self::assertEquals(140, $point->x->value);
        self::assertEquals(30, $point->y->value);
        $wrongOrder = $placement->fittingMatrix->then($matrix)->transform($this->point(1, 2));
        self::assertNotEquals($point, $wrongOrder);
    }

    public function testReversedRectangleCornersPreserveTheNormalizedPlacement(): void
    {
        $normal = new AnnotationAppearanceTransform($this->rectangle([0, 0, 10, 20]), $this->rectangle([30, 50, 90, 170]));
        $reverse = new AnnotationAppearanceTransform($this->rectangle([10, 20, 0, 0]), $this->rectangle([90, 170, 30, 50]));
        self::assertEquals($normal->effectiveMatrix, $reverse->effectiveMatrix);
    }

    public function testShearedBoundsUseAllFourCornersAndDoNotScaleTheSourceTwice(): void
    {
        $placement = new AnnotationAppearanceTransform($this->rectangle([-2, -1, 3, 4]), $this->rectangle([0, 0, 40, 90]), $this->matrix([2, 1, -1, 3, 9, -5]));
        $corners = [];
        foreach ([[-2, -1], [-2, 4], [3, -1], [3, 4]] as [$x, $y]) {
            $corners[] = $placement->effectiveMatrix->transform($this->point($x, $y));
        }
        self::assertEqualsWithDelta(0, min(array_map(fn (Point $point): int|float => $point->x->value, $corners)), 1e-12);
        self::assertEqualsWithDelta(40, max(array_map(fn (Point $point): int|float => $point->x->value, $corners)), 1e-12);
        self::assertEqualsWithDelta(0, min(array_map(fn (Point $point): int|float => $point->y->value, $corners)), 1e-12);
        self::assertEqualsWithDelta(90, max(array_map(fn (Point $point): int|float => $point->y->value, $corners)), 1e-12);
    }

    public function testAnInvisibleSignatureRectangleProducesAFiniteCollapsedTransform(): void
    {
        $placement = new AnnotationAppearanceTransform($this->rectangle([0, 0, 10, 10]), $this->rectangle([30, 40, 30, 40]));
        self::assertEquals([0, 0, 0, 0, 30, 40], array_map(fn (NumericType $number): int|float => $number->value, $placement->effectiveMatrix->operands()));
    }

    public function testAZeroExtentSourceCannotInventAnInfiniteTransform(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnnotationAppearanceTransform($this->rectangle([0, 0, 0, 10]), $this->rectangle([0, 0, 20, 20]));
    }

    private function rectangle(array $values): Rectangle
    {
        return new Rectangle(...array_map(fn (int|float $value): NumericType => new NumericType($value), $values));
    }

    private function matrix(array $values): Matrix
    {
        return new Matrix(...array_map(fn (int|float $value): NumericType => new NumericType($value), $values));
    }

    private function point(int|float $x, int|float $y): Point
    {
        return new Point(new NumericType($x), new NumericType($y));
    }
}
