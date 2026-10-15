<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** ISO1 Table263 pp490–491; ISO2 Table268 pp614–615. This validates declarations, not display rounding. */
final readonly class NumberFormatGraphValidation
{
    public function validate(ArrayType $array, DocumentGraphContext $graph): void
    {
        if (count($array) === 0) {
            throw new \InvalidArgumentException('A measurement number-format array contains one or more dictionaries.');
        }
        foreach ($array->items as $position => $reference) {
            $scope = $graph->forDirectValue($reference);
            $dictionary = $scope->resolve($reference) instanceof DictionaryType ? $scope->dictionary($reference) : null;
            if ($dictionary === null) {
                throw new \InvalidArgumentException('A measurement number-format array contains only dictionaries.');
            }
            $type = $this->value($dictionary, 'Type', $scope);
            if ($type !== null && (!$type instanceof NameType || $type->value !== 'NumberFormat')) {
                throw new \InvalidArgumentException('A number-format dictionary has Type NumberFormat when present.');
            }
            if (!$this->value($dictionary, 'U', $scope) instanceof StringType || !$this->value($dictionary, 'C', $scope) instanceof NumericType) {
                throw new \InvalidArgumentException('A number-format dictionary requires its U text label and C numeric conversion factor.');
            }
            foreach (['RT', 'RD', 'PS', 'SS'] as $key) {
                $value = $this->value($dictionary, $key, $scope);
                if ($value !== null && !$value instanceof StringType) {
                    throw new \InvalidArgumentException('Number-format separators and label spacing are text strings.');
                }
            }
            $order = $this->value($dictionary, 'O', $scope);
            if ($order !== null && (!$order instanceof NameType || !in_array($order->value, ['S', 'P'], true))) {
                throw new \InvalidArgumentException('Number-format label order O is S or P.');
            }
            $fraction = $this->value($dictionary, 'F', $scope);
            $precision = $this->value($dictionary, 'D', $scope);
            $fixed = $this->value($dictionary, 'FD', $scope);
            if (($fraction !== null && !$fraction instanceof NameType) || ($precision !== null && !$precision instanceof IntegerType) || ($fixed !== null && !$fixed instanceof BooleanType)) {
                throw new \InvalidArgumentException('Number-format F, D and FD use name, integer and boolean values.');
            }
            if ($position !== count($array) - 1) {
                continue; // Fraction controls are meaningful only in the last dictionary of this array.
            }
            $mode = $fraction instanceof NameType ? $fraction->value : 'D';
            if (!in_array($mode, ['D', 'F', 'R', 'T'], true)) {
                throw new \InvalidArgumentException('The last number-format F is D, F, R or T.');
            }
            if ($precision instanceof IntegerType && ($precision->value <= 0 || ($mode === 'D' && $precision->integer() % 10 !== 0))) {
                throw new \InvalidArgumentException('The last number-format D is positive and decimal precision is a multiple of ten.');
            }
        }
    }

    private function value(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $entry = $graph->entry($dictionary, $key);
        return $entry === null ? null : $graph->resolve($entry);
    }
}
