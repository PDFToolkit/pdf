<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** Rejects provable straight-line stack violations; never treats sampling or branch merging as a proof. */
final readonly class StackShapeValidation
{
    public function validate(Program $program, int $inputs, int $outputs): void
    {
        foreach ($program->instructions as $instruction) {
            if ($instruction instanceof Conditional || in_array($instruction, [Operator::Copy, Operator::Index, Operator::Roll], true)) {
                return; // Data-dependent stack shapes require explicit evaluation of the selected path.
            }
        }
        $stack = array_fill(0, $inputs, 'number');
        foreach ($program->instructions as $instruction) {
            if ($instruction instanceof Literal) {
                $stack[] = is_int($instruction->value) ? 'integer' : 'real';
                continue;
            }
            if ($instruction === Operator::TrueValue || $instruction === Operator::FalseValue) {
                $stack[] = 'boolean';
                continue;
            }
            if ($instruction === Operator::Duplicate) {
                $top = $this->pop($stack);
                array_push($stack, $top, $top);
                continue;
            }
            if ($instruction === Operator::Exchange) {
                $top = $this->pop($stack);
                $next = $this->pop($stack);
                array_push($stack, $top, $next);
                continue;
            }
            $right = $this->pop($stack);
            if ($instruction === Operator::Pop) {
                continue;
            }
            if (in_array($instruction, [Operator::Equal, Operator::NotEqual], true)) {
                $this->pop($stack);
                $stack[] = 'boolean';
                continue;
            }
            if ($instruction === Operator::Not) {
                $this->requireIntegerOrBoolean($right);
                $stack[] = $right;
                continue;
            }
            if (in_array($instruction, [Operator::And, Operator::Or, Operator::Xor], true)) {
                $left = $this->pop($stack);
                $this->requireIntegerOrBoolean($right);
                $this->requireIntegerOrBoolean($left);
                if (($right === 'boolean') !== ($left === 'boolean')) {
                    throw new \InvalidArgumentException('Calculator logical operands must both be booleans or integers.');
                }
                $stack[] = $right === 'boolean' ? 'boolean' : 'integer';
                continue;
            }
            $this->requireNumber($right);
            if (in_array($instruction, [Operator::Abs, Operator::Negate, Operator::Ceiling, Operator::Floor, Operator::Round, Operator::Truncate, Operator::SquareRoot, Operator::Sin, Operator::Cos, Operator::NaturalLogarithm, Operator::Logarithm, Operator::ConvertToInteger, Operator::ConvertToReal], true)) {
                $stack[] = match ($instruction) {
                    Operator::ConvertToInteger => 'integer',
                    Operator::SquareRoot, Operator::Sin, Operator::Cos, Operator::NaturalLogarithm, Operator::Logarithm, Operator::ConvertToReal => 'real',
                    default => 'number',
                };
                continue;
            }
            $left = $this->pop($stack);
            $this->requireNumber($left);
            if (in_array($instruction, [Operator::IntegerDivide, Operator::Modulo, Operator::Bitshift], true)) {
                if ($left === 'real' || $right === 'real') {
                    throw new \InvalidArgumentException('Calculator integer operators cannot receive proven real operands.');
                }
                $stack[] = 'integer';
            } else {
                $stack[] = in_array($instruction, [Operator::GreaterThan, Operator::GreaterThanOrEqual, Operator::LessThan, Operator::LessThanOrEqual], true) ? 'boolean' : 'number';
            }
        }
        if (count($stack) !== $outputs || in_array('boolean', $stack, true)) {
            throw new \InvalidArgumentException('A calculator must leave exactly its declared number of numeric outputs.');
        }
    }

    /** @param list<string> $stack */
    private function pop(array &$stack): string
    {
        return array_pop($stack) ?? throw new \InvalidArgumentException('Calculator program has a provable operand stack underflow.');
    }

    private function requireNumber(string $type): void
    {
        if ($type === 'boolean') {
            throw new \InvalidArgumentException('A calculator numeric operator cannot receive a proven boolean operand.');
        }
    }

    private function requireIntegerOrBoolean(string $type): void
    {
        if ($type === 'real') {
            throw new \InvalidArgumentException('A calculator logical operator requires boolean or integer operands.');
        }
    }
}
