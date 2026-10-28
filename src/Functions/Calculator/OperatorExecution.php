<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** ISO2 Annex B pp863–864; integers use the explicit PHP 64-bit implementation word. */
final readonly class OperatorExecution
{
    public function apply(Operator $operator, CalculatorStack $stack): void
    {
        if ($operator === Operator::TrueValue || $operator === Operator::FalseValue) {
            $stack->push($operator === Operator::TrueValue);
            return;
        }
        if ($operator === Operator::Pop) {
            $stack->pop();
            return;
        }
        if ($operator === Operator::Duplicate) {
            $stack->push($stack->index(0));
            return;
        }
        if ($operator === Operator::Exchange) {
            $top = $stack->pop();
            $next = $stack->pop();
            $stack->push($top);
            $stack->push($next);
            return;
        }
        if ($operator === Operator::Copy) {
            $stack->copy($this->integer($stack->pop()));
            return;
        }
        if ($operator === Operator::Index) {
            $stack->push($stack->index($this->integer($stack->pop())));
            return;
        }
        if ($operator === Operator::Roll) {
            $amount = $this->integer($stack->pop());
            $stack->roll($this->integer($stack->pop()), $amount);
            return;
        }
        if (in_array($operator, [Operator::Abs, Operator::Ceiling, Operator::Cos, Operator::ConvertToInteger, Operator::ConvertToReal, Operator::Floor, Operator::NaturalLogarithm, Operator::Logarithm, Operator::Negate, Operator::Round, Operator::Sin, Operator::SquareRoot, Operator::Truncate, Operator::Not], true)) {
            $stack->push($this->unary($operator, $stack->pop()));
            return;
        }
        $right = $stack->pop();
        $left = $stack->pop();
        $stack->push($this->binary($operator, $left, $right));
    }

    private function unary(Operator $operator, int|float|bool $value): int|float|bool
    {
        if ($operator === Operator::Not) {
            return is_bool($value) ? !$value : ~$this->integer($value);
        }
        $number = $this->number($value);
        return match ($operator) {
            Operator::Abs => abs($number),
            Operator::Negate => -$number,
            Operator::Ceiling => is_int($number) ? $number : ceil($number),
            Operator::Floor => is_int($number) ? $number : floor($number),
            // PostScript round chooses the greater integer at a half-integer tie.
            Operator::Round => is_int($number) ? $number : $this->round($number),
            Operator::Truncate => is_int($number) ? $number : ($number < 0 ? ceil($number) : floor($number)),
            Operator::ConvertToInteger => $this->convertInteger($number),
            Operator::ConvertToReal => (float) $number,
            Operator::Sin => sin(deg2rad(fmod((float) $number, 360.0))),
            Operator::Cos => cos(deg2rad(fmod((float) $number, 360.0))),
            Operator::SquareRoot => $number < 0 ? throw new \DomainException('Calculator sqrt requires a nonnegative operand.') : sqrt($number),
            Operator::NaturalLogarithm => $number <= 0 ? throw new \DomainException('Calculator ln requires a positive operand.') : log($number),
            Operator::Logarithm => $number <= 0 ? throw new \DomainException('Calculator log requires a positive operand.') : log10($number),
            default => throw new \LogicException('The unary calculator operator was classified explicitly.'),
        };
    }

    private function binary(Operator $operator, int|float|bool $left, int|float|bool $right): int|float|bool
    {
        if ($operator === Operator::Equal || $operator === Operator::NotEqual) {
            $equal = is_bool($left) || is_bool($right) ? $left === $right : $left == $right;
            return $operator === Operator::Equal ? $equal : !$equal;
        }
        if (in_array($operator, [Operator::And, Operator::Or, Operator::Xor], true)) {
            if (is_bool($left) && is_bool($right)) {
                return match ($operator) {
                    Operator::And => $left && $right,
                    Operator::Or => $left || $right,
                    default => $left !== $right,
                };
            }
            $a = $this->integer($left);
            $b = $this->integer($right);
            return match ($operator) {
                Operator::And => $a & $b,
                Operator::Or => $a | $b,
                default => $a ^ $b,
            };
        }
        if (in_array($operator, [Operator::IntegerDivide, Operator::Modulo, Operator::Bitshift], true)) {
            $a = $this->integer($left);
            $b = $this->integer($right);
            if ($operator === Operator::Bitshift) {
                if ($b >= PHP_INT_SIZE * 8 || $b <= -PHP_INT_SIZE * 8) {
                    return 0;
                }
                return $b >= 0 ? $a << $b : (($a >> -$b) & (PHP_INT_MAX >> (-$b - 1)));
            }
            if ($b === 0) {
                throw new \DomainException('Calculator integer division by zero.');
            }
            if ($operator === Operator::IntegerDivide && $a === PHP_INT_MIN && $b === -1) {
                throw new \DomainException('Calculator idiv exceeds the explicit integer range.');
            }
            return $operator === Operator::IntegerDivide ? intdiv($a, $b) : $a % $b;
        }
        $a = $this->number($left);
        $b = $this->number($right);
        return match ($operator) {
            Operator::Add => $a + $b,
            Operator::Subtract => $a - $b,
            Operator::Multiply => $a * $b,
            Operator::Divide => $b == 0 ? throw new \DomainException('Calculator division by zero.') : (float) ($a / $b),
            Operator::Exponentiate => $a == 0 && $b < 0 ? throw new \DomainException('Calculator exp has an undefined zero base.') : (float) ($a ** $b),
            Operator::Atan => $a == 0 && $b == 0 ? throw new \DomainException('Calculator atan requires a nonzero vector.') : fmod(rad2deg(atan2($a, $b)) + 360.0, 360.0),
            Operator::GreaterThanOrEqual => $a >= $b,
            Operator::GreaterThan => $a > $b,
            Operator::LessThanOrEqual => $a <= $b,
            Operator::LessThan => $a < $b,
            default => throw new \LogicException('Conditional operators require lexical bodies.'),
        };
    }

    private function round(float $number): float
    {
        $floor = floor($number);
        return $number - $floor >= 0.5 ? $floor + 1.0 : $floor;
    }

    private function number(int|float|bool $value): int|float
    {
        return is_bool($value) ? throw new \UnexpectedValueException('Calculator operator requires a numeric operand.') : $value;
    }

    private function integer(int|float|bool $value): int
    {
        return is_int($value) ? $value : throw new \UnexpectedValueException('Calculator operator requires an integer operand.');
    }

    private function convertInteger(int|float $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value < PHP_INT_MIN || $value >= -(float) PHP_INT_MIN) {
            throw new \DomainException('Calculator cvi exceeds the explicit integer range.');
        }
        return (int) $value;
    }
}
