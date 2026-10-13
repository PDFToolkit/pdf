<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Syntax\Object\NumericType;

/** Exact local PDF blend/compositing arithmetic (11.3.3-11.3.6), without rasterization. */
final readonly class BlendFunction
{
    public function __construct(public BlendMode $mode, public BlendingColourSpace $colourSpace)
    {
        if (!$mode->separable() && !in_array($colourSpace->space->componentCount(), [3, 4], true)) {
            throw new \InvalidArgumentException('Nonseparable blend formulas require RGB or CMYK components.');
        }
    }

    public function blend(ColourComponents $backdrop, ColourComponents $source): ColourComponents
    {
        $b = $this->components($backdrop);
        $s = $this->components($source);
        $subtractive = $this->colourSpace->subtractive();
        if ($subtractive) {
            $b = array_map(static fn (int|float $c): int|float => 1 - $c, $b);
            $s = array_map(static fn (int|float $c): int|float => 1 - $c, $s);
        }
        if ($this->mode->separable()) {
            $result = [];
            foreach ($b as $index => $component) {
                $result[] = $this->scalar($component, $s[$index]);
            }
        } else {
            $rgbB = [$b[0], $b[1], $b[2]];
            $rgbS = [$s[0], $s[1], $s[2]];
            $result = match ($this->mode) {
                BlendMode::Hue => $this->setLum($this->setSat($rgbS, max($rgbB) - min($rgbB)), $this->lum($rgbB)),
                BlendMode::Saturation => $this->setLum($this->setSat($rgbB, max($rgbS) - min($rgbS)), $this->lum($rgbB)),
                BlendMode::Color => $this->setLum($rgbS, $this->lum($rgbB)),
                BlendMode::Luminosity => $this->setLum($rgbB, $this->lum($rgbS)),
                default => throw new \LogicException('Unexpected nonseparable mode.'),
            };
            if (count($b) === 4) {
                $result[] = $this->mode === BlendMode::Luminosity ? $s[3] : $b[3];
            }
        }
        if ($subtractive) {
            $result = array_map(static fn (int|float $c): int|float => 1 - $c, $result);
        }
        return new ColourComponents($this->colourSpace->space, ...array_map(static fn (int|float $c): NumericType => new NumericType(max(0, min(1, $c))), $result));
    }

    public function composite(ColourComponents $backdrop, NumericType $backdropAlpha, ColourComponents $source, NumericType $sourceAlpha): CompositingResult
    {
        foreach ([$backdropAlpha, $sourceAlpha] as $alpha) {
            if ($alpha->value < 0 || $alpha->value > 1) {
                throw new \InvalidArgumentException('Compositing alphas must be unit values.');
            }
        }
        $b = $this->components($backdrop);
        $s = $this->components($source);
        $ab = $backdropAlpha->value;
        $as = $sourceAlpha->value;
        $ar = 1 - (1 - $ab) * (1 - $as);
        if ($ar == 0) {
            return new CompositingResult(new NumericType(0), null);
        }
        $blend = $this->blend($backdrop, $source);
        $numbers = [];
        foreach ($b as $index => $number) {
            $numbers[] = new NumericType(((1 - $as) * $ab * $number + (1 - $ab) * $as * $s[$index] + $ab * $as * $blend->numbers[$index]->value) / $ar);
        }
        return new CompositingResult(new NumericType($ar), new ColourComponents($this->colourSpace->space, ...$numbers));
    }

    /** @return non-empty-list<int|float> */
    private function components(ColourComponents $colour): array
    {
        if ($colour->space !== $this->colourSpace->space) {
            throw new \InvalidArgumentException('Blend values must belong to the same explicit blending colour space.');
        }
        $numbers = array_map(static fn (NumericType $n): int|float => $n->value, $colour->clipped()->numbers);
        if ($numbers === []) {
            throw new \InvalidArgumentException('Blending needs at least one colour component.');
        }
        return $numbers;
    }

    private function scalar(int|float $b, int|float $s): int|float
    {
        return match ($this->mode) {
            BlendMode::Normal, BlendMode::Compatible => $s,
            BlendMode::Multiply => $b * $s,
            BlendMode::Screen => $b + $s - $b * $s,
            BlendMode::Overlay => $b <= 0.5 ? 2 * $b * $s : 1 - 2 * (1 - $b) * (1 - $s),
            BlendMode::Darken => min($b, $s),
            BlendMode::Lighten => max($b, $s),
            BlendMode::ColorDodge => $s == 1 ? 1 : min(1, $b / (1 - $s)),
            BlendMode::ColorBurn => $s == 0 ? 0 : 1 - min(1, (1 - $b) / $s),
            BlendMode::HardLight => $s <= 0.5 ? 2 * $b * $s : 1 - 2 * (1 - $b) * (1 - $s),
            BlendMode::SoftLight => $s <= 0.5 ? $b - (1 - 2 * $s) * $b * (1 - $b) : $b + (2 * $s - 1) * (($b <= 0.25 ? ((16 * $b - 12) * $b + 4) * $b : sqrt($b)) - $b),
            BlendMode::Difference => abs($b - $s),
            BlendMode::Exclusion => $b + $s - 2 * $b * $s,
            default => throw new \LogicException('A nonseparable mode cannot be evaluated as one scalar.'),
        };
    }

    /** @param array{int|float,int|float,int|float} $c */
    private function lum(array $c): float
    {
        return 0.3 * $c[0] + 0.59 * $c[1] + 0.11 * $c[2];
    }

    /**
     * @param array{int|float,int|float,int|float} $c
     * @return array{int|float,int|float,int|float}
     */
    private function setLum(array $c, int|float $luminosity): array
    {
        $delta = $luminosity - $this->lum($c);
        $c = [$c[0] + $delta, $c[1] + $delta, $c[2] + $delta];
        $l = $this->lum($c);
        $minimum = min($c);
        $maximum = max($c);
        if ($minimum < 0) {
            $c = [$l + ($c[0] - $l) * $l / ($l - $minimum), $l + ($c[1] - $l) * $l / ($l - $minimum), $l + ($c[2] - $l) * $l / ($l - $minimum)];
        }
        if ($maximum > 1) {
            $c = [$l + ($c[0] - $l) * (1 - $l) / ($maximum - $l), $l + ($c[1] - $l) * (1 - $l) / ($maximum - $l), $l + ($c[2] - $l) * (1 - $l) / ($maximum - $l)];
        }
        return $c;
    }

    /**
     * @param array{int|float,int|float,int|float} $c
     * @return array{int|float,int|float,int|float}
     */
    private function setSat(array $c, int|float $saturation): array
    {
        $indices = [0, 1, 2];
        usort($indices, static fn (int $a, int $b): int => $c[$a] <=> $c[$b]);
        [$minimum, $middle, $maximum] = $indices;
        if ($c[$maximum] > $c[$minimum]) {
            $c[$middle] = ($c[$middle] - $c[$minimum]) * $saturation / ($c[$maximum] - $c[$minimum]);
            $c[$maximum] = $saturation;
        } else {
            $c[$middle] = $c[$maximum] = 0;
        }
        $c[$minimum] = 0;
        return $c;
    }
}
