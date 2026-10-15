<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

final readonly class ChoiceOptions implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $options = $values->value($dictionary, 'Opt');
        if ($options instanceof ArrayType) {
            foreach ($options->items as $option) {
                $option = $option instanceof IndirectReference ? $option->target() : $option;
                if ($option instanceof StringType) {
                    continue;
                }
                if (!$option instanceof ArrayType || count($option) !== 2) {
                    throw new \InvalidArgumentException('A choice option requires a string or two-string export/display pair.');
                }
                foreach ($option->items as $text) {
                    $text = $text instanceof IndirectReference ? $text->target() : $text;
                    if (!$text instanceof StringType) {
                        throw new \InvalidArgumentException('Choice export/display values require text strings.');
                    }
                }
            }
        }
        $indices = $values->numbers($dictionary, 'I');
        if ($indices !== null) {
            (new NumberArray('I', minimum: 0, integer: true, ascending: true))->validate($dictionary);
            foreach ($indices as $index) {
                if (!$options instanceof ArrayType || $index >= count($options)) {
                    throw new \InvalidArgumentException('A choice selection index must identify an /Opt item.');
                }
            }
        }
        $top = $values->value($dictionary, 'TI');
        if ($top instanceof IntegerType && $top->integer() !== 0 && (!$options instanceof ArrayType || $top->integer() >= count($options))) {
            throw new \InvalidArgumentException('/TI must identify an /Opt item.');
        }
    }
}
