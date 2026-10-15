<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

final readonly class PredictorParameters implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $predictor = $values->value($dictionary, 'Predictor');
        if ($predictor instanceof IntegerType && $predictor->integer() > 1) {
            return;
        }
        foreach (['Colors', 'BitsPerComponent', 'Columns'] as $key) {
            if ($values->value($dictionary, $key) !== null) {
                throw new \InvalidArgumentException('/' . $key . ' may be used only when /Predictor is greater than one.');
            }
        }
    }
}
