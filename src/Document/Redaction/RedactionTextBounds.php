<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** Native simple-font placement using explicitly verified glyph geometry; declared FontBBox is never proof. */
final readonly class RedactionTextBounds
{
    public function __construct(private ?RedactionFontGeometry $geometry = null)
    {
    }
    public function state(Operator $operator, RedactionGraphicsState $state, DictionaryType $resources): void
    {
        $values = $operator->operands;
        $number = static fn (int $index): float => ($values[$index] ?? null) instanceof NumericType ? (float) $values[$index]->value : throw new \InvalidArgumentException('Expected a numeric text operand.');
        switch ($operator->symbol()) {
            case 'Tf':
                $fonts = $this->value($resources->get('Font'));
                $font = $fonts instanceof DictionaryType && ($values[0] ?? null) instanceof NameType ? $this->value($fonts->get($values[0])) : null;
                $state->font = $font instanceof DictionaryType ? $font : null;
                $state->fontSize = $number(1);
                break;
            case 'Tc': $state->characterSpacing = $number(0);
                break;
            case 'Tw': $state->wordSpacing = $number(0);
                break;
            case 'Tz': $state->horizontalScaling = $number(0) / 100;
                break;
            case 'TL': $state->leading = $number(0);
                break;
            case 'Ts': $state->rise = $number(0);
                break;
            case 'Tr': $state->textMode = (int) $number(0);
                break;
            case 'BT': $state->textMatrix = $state->lineMatrix = new Matrix();
                break;
            case 'Tm':
                $state->textMatrix = $state->lineMatrix = new Matrix(...array_map(static fn (int $i): NumericType => new NumericType($number($i)), range(0, 5)));
                break;
            case 'TD': $state->leading = -$number(1); // fall through
                // no break
            case 'Td': $this->move($state, $number(0), $number(1));
                break;
            case 'T*': $this->move($state, 0, -$state->leading);
                break;
        }
    }

    /** Null means geometry cannot be proven; the entire containing text object is removed. */
    public function showing(Operator $operator, RedactionGraphicsState $state): ?RedactionBounds
    {
        $values = $operator->operands;
        $symbol = $operator->symbol();
        if ($symbol === '"') {
            $state->wordSpacing = $values[0] instanceof NumericType ? (float) $values[0]->value : 0;
            $state->characterSpacing = $values[1] instanceof NumericType ? (float) $values[1]->value : 0;
        }
        if (in_array($symbol, ["'", '"'], true)) {
            $this->move($state, 0, -$state->leading);
        }
        $items = $symbol === 'TJ' && $values[0] instanceof ArrayType ? $values[0]->items : [$values[$symbol === '"' ? 2 : 0]];
        $font = $state->font;
        $subtype = $this->value($font?->get('Subtype'));
        if ($this->geometry === null || $font === null || !$subtype instanceof NameType || !in_array($subtype->value, ['Type1', 'MMType1', 'TrueType'], true)) {
            return null;
        }
        $bounds = null;
        foreach ($items as $item) {
            if ($item instanceof NumericType) {
                $this->advance($state, -(float) $item->value / 1000 * $state->fontSize * $state->horizontalScaling);
                continue;
            }
            if (!$item instanceof StringType) {
                return null;
            }
            foreach (str_split($item->value) as $byte) {
                $glyph = $this->geometry->glyph($font, ord($byte));
                if ($glyph === null) {
                    return null;
                }
                $matrix = new Matrix(new NumericType($state->fontSize * $state->horizontalScaling / 1000), new NumericType(0), new NumericType(0), new NumericType($state->fontSize / 1000), new NumericType(0), new NumericType($state->rise));
                $current = $glyph->outline->transformed($matrix->then($state->textMatrix)->then($state->matrix));
                if (in_array($state->textMode, [1, 2, 5, 6], true)) {
                    $current = $current->expanded($state->lineWidth * max(1, $state->miterLimit) * $this->scale($state->matrix));
                }
                $bounds = $bounds === null ? $current : $bounds->union($current);
                $this->advance($state, (($glyph->advance / 1000 * $state->fontSize) + $state->characterSpacing + ($byte === ' ' ? $state->wordSpacing : 0)) * $state->horizontalScaling);
            }
        }
        return $bounds ?? new RedactionBounds(0, 0, 0, 0);
    }

    public function scale(Matrix $matrix): float
    {
        // Frobenius norm bounds the largest singular value, including sheared transforms.
        return sqrt($matrix->a->value ** 2 + $matrix->b->value ** 2 + $matrix->c->value ** 2 + $matrix->d->value ** 2);
    }

    private function advance(RedactionGraphicsState $state, float $distance): void
    {
        $state->textMatrix = (new Matrix(e: new NumericType($distance)))->then($state->textMatrix);
    }

    private function move(RedactionGraphicsState $state, float $x, float $y): void
    {
        $state->textMatrix = $state->lineMatrix = (new Matrix(e: new NumericType($x), f: new NumericType($y)))->then($state->lineMatrix);
    }

    private function value(?BasicType $value): ?BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }
}
