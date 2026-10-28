<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** ISO2 7.10.5 pp146–147: calculator grammar and the mandatory 255-level writer ceiling. */
final readonly class ProgramCompiler
{
    public function __construct(private CalculatorLimits $limits = new CalculatorLimits())
    {
    }

    public function compile(Procedure $procedure): Program
    {
        $count = 0;
        return $this->body($procedure, 1, $count);
    }

    /** @param list<Instruction> $source */
    private function instruction(array $source, int $index): ?Instruction
    {
        return $source[$index] ?? null;
    }

    private function body(Procedure $procedure, int $depth, int &$count): Program
    {
        if ($depth > $this->limits->maxNesting) {
            throw new \LengthException('Calculator brace nesting exceeds its explicit limit (at most 255).');
        }
        $compiled = [];
        $source = $procedure->instructions;
        for ($index = 0; $index < count($source); ++$index) {
            if (++$count > $this->limits->maxInstructions) {
                throw new \LengthException('Calculator source exceeds its explicit instruction budget.');
            }
            $instruction = $source[$index];
            if ($instruction instanceof Number) {
                $compiled[] = new Literal($instruction->toPostScript());
            } elseif ($instruction instanceof Procedure) {
                $consequent = $this->body($instruction, $depth + 1, $count);
                $next = $this->instruction($source, ++$index);
                $alternative = null;
                if ($next instanceof Procedure) {
                    $alternative = $this->body($next, $depth + 1, $count);
                    $next = $this->instruction($source, ++$index);
                }
                if ($next !== ($alternative === null ? Operator::IfThen : Operator::IfElse)) {
                    throw new \InvalidArgumentException('Calculator procedures occur only immediately before their if or ifelse operator.');
                }
                $compiled[] = new Conditional($consequent, $alternative);
            } elseif ($instruction instanceof Operator && $instruction !== Operator::IfThen && $instruction !== Operator::IfElse) {
                $compiled[] = $instruction;
            } else {
                throw new \InvalidArgumentException('A calculator requires its explicit Number, Operator and conditional Procedure objects.');
            }
        }
        return new Program(...$compiled);
    }
}
