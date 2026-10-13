<?php

declare(strict_types=1);

namespace PDF\Document\Measurements;

use PDF\Specification\Adobe\Definition\ThreeDUnitsDictionaryTable9_33aDefinition;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;

/** Adobe supplement, 9.5.6, table 9.33a pp. 59–60 and the processing algorithm p. 61. */
final readonly class ThreeDUnitMapping
{
    public NumericType $m;
    public NumericType $n;
    public StringType $units;

    public function __construct(public DictionaryType $dictionary, public UnitMappingInterpretation $interpretation)
    {
        $definition = new ThreeDUnitsDictionaryTable9_33aDefinition();
        foreach (['TSm', 'TSn', 'TU', 'USm', 'USn', 'UU', 'DSm', 'DSn', 'DU'] as $key) {
            $value = $this->value($key);
            if ($value !== null) {
                $definition->entry($key)->type->validate($value);
            }
        }
        foreach ([['TSm', 'TSn', 'TU'], ['USm', 'USn', 'UU'], ['DSm', 'DSn', 'DU']] as [$mKey, $nKey, $labelKey]) {
            if (($this->value($mKey) !== null || $this->value($nKey) !== null) && $this->value($labelKey) === null) {
                throw new \InvalidArgumentException('/' . $labelKey . ' is required when /' . $mKey . ' or /' . $nKey . ' is defined.');
            }
        }
        $m = new NumericType(1);
        $n = new NumericType(1);
        $units = new LiteralString('Model Units');
        foreach ([['TSm', 'TSn', 'TU'], ['USm', 'USn', 'UU']] as [$mKey, $nKey, $labelKey]) {
            $m = $this->number($mKey) ?? $m;
            $n = $this->number($nKey) ?? $n;
            $units = $this->label($labelKey) ?? $units;
        }
        if (($displayM = $this->number('DSm')) !== null) {
            $m = $this->multiply($m, $displayM);
        }
        if (($displayN = $this->number('DSn')) !== null) {
            $n = $this->multiply($n, $displayN);
        }
        $this->m = $m;
        $this->n = $n;
        $this->units = $this->label('DU') ?? $units;
    }

    public function map(NumericType $modelDistance): MeasuredDistance
    {
        return new MeasuredDistance($this->interpretation->map($modelDistance, $this->m, $this->n), $this->units);
    }

    private function value(string $key): ?BasicType
    {
        $value = $this->dictionary->get($key);
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        // ISO 32000-1 7.3.7: a null-valued dictionary entry is absent.
        return $target instanceof NullType ? null : $target;
    }

    private function number(string $key): ?NumericType
    {
        $value = $this->value($key);
        if ($value !== null && !$value instanceof NumericType) {
            throw new \InvalidArgumentException('/' . $key . ' requires a PDF number.');
        }
        return $value;
    }

    private function label(string $key): ?StringType
    {
        $value = $this->value($key);
        if ($value !== null && !$value instanceof StringType) {
            throw new \InvalidArgumentException('/' . $key . ' requires a PDF text string.');
        }
        return $value;
    }

    private function multiply(NumericType $left, NumericType $right): NumericType
    {
        $value = $left->value * $right->value;
        if (!is_finite($value) || ($value == 0 && $left->value != 0 && $right->value != 0)) {
            throw new \OutOfRangeException('A display-unit scale product exceeds the representable numeric range.');
        }
        return new NumericType($value);
    }
}
