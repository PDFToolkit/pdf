<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** One invocation owns its stack and operation counter. */
final class Evaluation
{
    private int $operations = 0;
    public readonly CalculatorStack $stack;

    public function __construct(private readonly CalculatorLimits $limits, private readonly OperatorExecution $operators = new OperatorExecution())
    {
        $this->stack = new CalculatorStack($limits);
    }

    public function execute(Program $program, int $depth = 1): void
    {
        if ($depth > $this->limits->maxNesting) {
            throw new \LengthException('Calculator evaluation exceeds its explicit nesting budget.');
        }
        foreach ($program->instructions as $instruction) {
            if (++$this->operations > $this->limits->maxOperations) {
                throw new \LengthException('Calculator evaluation exceeds its explicit operation budget.');
            }
            if ($instruction instanceof Literal) {
                $this->stack->push($instruction->value);
            } elseif ($instruction instanceof Conditional) {
                $condition = $this->stack->pop();
                if (!is_bool($condition)) {
                    throw new \UnexpectedValueException('Calculator condition requires a boolean operand.');
                }
                $selected = $condition ? $instruction->consequent : $instruction->alternative;
                if ($selected !== null) {
                    $this->execute($selected, $depth + 1);
                }
            } else {
                $this->operators->apply($instruction, $this->stack);
            }
        }
    }
}
