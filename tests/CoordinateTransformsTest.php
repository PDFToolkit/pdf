<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\CoordinateSystems\Point;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\TestCase;

final class CoordinateTransformsTest extends TestCase
{
    public function testScaleThenTranslateUsesPdfRowVectorOrder(): void
    {
        $scale = new Matrix(new NumericType(2), new NumericType(0), new NumericType(0), new NumericType(3), new NumericType(0), new NumericType(0));
        $translate = new Matrix(e: new NumericType(10), f: new NumericType(20));
        $point = new Point(new NumericType(4), new NumericType(5));
        $result = $scale->then($translate)->transform($point);
        self::assertEqualsWithDelta(18, $result->x->value, 1e-12);
        self::assertEqualsWithDelta(35, $result->y->value, 1e-12);
        $opposite = $translate->then($scale)->transform($point);
        self::assertEqualsWithDelta(28, $opposite->x->value, 1e-12);
        self::assertEqualsWithDelta(75, $opposite->y->value, 1e-12);
    }

    public function testInverseRecoversCoordinatesAfterRotationShearAndTranslation(): void
    {
        $matrix = new Matrix(new NumericType(0.5), new NumericType(2), new NumericType(-1), new NumericType(0.75), new NumericType(9), new NumericType(-4));
        $point = new Point(new NumericType(13), new NumericType(-7));
        $recovered = $matrix->inverse()->transform($matrix->transform($point));
        self::assertEqualsWithDelta($point->x->value, $recovered->x->value, 1e-12);
        self::assertEqualsWithDelta($point->y->value, $recovered->y->value, 1e-12);
    }

    public function testSingularMatricesFailExplicitlyInsteadOfInventingAnInverse(): void
    {
        $this->expectException(\LogicException::class);
        (new Matrix(new NumericType(1), new NumericType(2), new NumericType(2), new NumericType(4)))->inverse();
    }
}
