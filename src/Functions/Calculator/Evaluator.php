<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

use PDF\Functions\NumberArray;
use PDF\Functions\PostScriptCalculatorFunction;
use PDF\Syntax\Object\NumericType;

/** Explicit native Type4 evaluation with input Domain/output Range clipping and no external effects. */
final readonly class Evaluator
{
    public function __construct(private CalculatorLimits $limits = new CalculatorLimits())
    {
    }

    public function evaluate(PostScriptCalculatorFunction $function, NumericType ...$inputs): NumberArray
    {
        $inputs = array_values($inputs);
        if (count($inputs) !== $function->inputCount()) {
            throw new \InvalidArgumentException('Calculator input count must match its Domain.');
        }
        $execution = new Evaluation($this->limits);
        foreach ($inputs as $index => $input) {
            $value = (new Literal((string) $input))->value;
            $interval = $function->domain()->intervals[$index];
            $execution->stack->push(max($interval->minimum->value, min($interval->maximum->value, $value)));
        }
        $execution->execute($function->program);
        $values = $execution->stack->values();
        if (count($values) !== $function->outputCount()) {
            throw new \UnexpectedValueException('Calculator execution must leave exactly its declared number of outputs.');
        }
        $outputs = [];
        foreach ($values as $index => $value) {
            if (is_bool($value)) {
                throw new \UnexpectedValueException('Calculator outputs must all be numeric.');
            }
            $interval = $function->range()->intervals[$index];
            $outputs[] = new NumericType(max($interval->minimum->value, min($interval->maximum->value, $value)));
        }
        return new NumberArray(...$outputs);
    }
}
