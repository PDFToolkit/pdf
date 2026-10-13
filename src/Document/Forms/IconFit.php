<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Specification\Definition\IconFitDictionaryTable247Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Icon scaling/placement from ISO table 247, PDF p.471. Viewport bounds are explicit. */
final class IconFit extends SchemaDictionary
{
    public function __construct(public readonly IconScaleWhen $when = IconScaleWhen::Always, public readonly IconScaling $scaling = IconScaling::Proportional, public readonly IconAlignment $alignment = new IconAlignment(), public readonly BooleanType $fitBounds = new BooleanType(false))
    {
        parent::__construct(
            new IconFitDictionaryTable247Definition(),
            new Entry(new NameType('SW'), new NameType($when->value)),
            new Entry(new NameType('S'), new NameType($scaling->value)),
            new Entry(new NameType('A'), $alignment),
            new Entry(new NameType('FB'), $fitBounds),
        );
    }

    /** Apply to the icon's effective bounds after its own form matrix; callers supply border-adjusted viewport. */
    public function transform(Rectangle $icon, Rectangle $viewport): Matrix
    {
        $width = abs($icon->upperRightX->value - $icon->lowerLeftX->value);
        $height = abs($icon->upperRightY->value - $icon->lowerLeftY->value);
        $availableWidth = abs($viewport->upperRightX->value - $viewport->lowerLeftX->value);
        $availableHeight = abs($viewport->upperRightY->value - $viewport->lowerLeftY->value);
        if ($width == 0 || $height == 0) {
            throw new \InvalidArgumentException('Icon effective bounds must have positive width and height.');
        }
        $scale = match ($this->when) {
            IconScaleWhen::Always => true,
            IconScaleWhen::Bigger => $width > $availableWidth || $height > $availableHeight,
            IconScaleWhen::Smaller => $width <= $availableWidth && $height <= $availableHeight,
            IconScaleWhen::Never => false,
        };
        $sx = $scale ? $availableWidth / $width : 1;
        $sy = $scale ? $availableHeight / $height : 1;
        if ($this->scaling === IconScaling::Proportional) {
            $sx = $sy = min($sx, $sy);
        }
        $leftoverX = $availableWidth - $sx * $width;
        $leftoverY = $availableHeight - $sy * $height;
        $tx = min($viewport->lowerLeftX->value, $viewport->upperRightX->value) + $leftoverX * $this->alignment->left->value - $sx * min($icon->lowerLeftX->value, $icon->upperRightX->value);
        $ty = min($viewport->lowerLeftY->value, $viewport->upperRightY->value) + $leftoverY * $this->alignment->bottom->value - $sy * min($icon->lowerLeftY->value, $icon->upperRightY->value);
        return new Matrix(new NumericType($sx), new NumericType(0), new NumericType(0), new NumericType($sy), new NumericType($tx), new NumericType($ty));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Icon fit parameters are supplied by its constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Icon fit parameters are supplied by its constructor.');
    }
}
