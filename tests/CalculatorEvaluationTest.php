<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Functions\Calculator\CalculatorLimits;
use PDF\Functions\Calculator\Evaluator;
use PDF\Functions\Calculator\Instruction;
use PDF\Functions\Calculator\Number;
use PDF\Functions\Calculator\Operator as O;
use PDF\Functions\Calculator\Procedure;
use PDF\Functions\Calculator\ProgramCompiler;
use PDF\Functions\Domain;
use PDF\Functions\NumberInterval;
use PDF\Functions\PostScriptCalculatorFunction;
use PDF\Functions\Range;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\RealType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalculatorEvaluationTest extends TestCase
{
    /** @return iterable<string,array{list<int|float|bool>,list<O>,list<int|float|bool>}> */
    public static function operations(): iterable
    {
        yield 'abs' => [[-8], [O::Abs], [8]];
        yield 'add' => [[8, 3], [O::Add], [11]];
        yield 'atan' => [[1, 0], [O::Atan], [90.0]];
        yield 'ceiling' => [[-2.5], [O::Ceiling], [-2.0]];
        yield 'cos' => [[60], [O::Cos], [0.5]];
        yield 'cvi' => [[-2.9], [O::ConvertToInteger], [-2]];
        yield 'cvr' => [[2], [O::ConvertToReal], [2.0]];
        yield 'div' => [[7, 2], [O::Divide], [3.5]];
        yield 'exp returns real' => [[2, 3], [O::Exponentiate], [8.0]];
        yield 'floor' => [[-2.5], [O::Floor], [-3.0]];
        yield 'idiv' => [[-7, 2], [O::IntegerDivide], [-3]];
        yield 'ln' => [[1], [O::NaturalLogarithm], [0.0]];
        yield 'log' => [[100], [O::Logarithm], [2.0]];
        yield 'mod' => [[-7, 2], [O::Modulo], [-1]];
        yield 'mul' => [[8, 3], [O::Multiply], [24]];
        yield 'neg' => [[-3], [O::Negate], [3]];
        yield 'round negative tie' => [[-2.5], [O::Round], [-2.0]];
        yield 'round positive tie' => [[2.5], [O::Round], [3.0]];
        yield 'round large exact real' => [[4503599627370497.0], [O::Round], [4503599627370497.0]];
        yield 'sin' => [[30], [O::Sin], [0.5]];
        yield 'sqrt' => [[4], [O::SquareRoot], [2.0]];
        yield 'sub' => [[8, 3], [O::Subtract], [5]];
        yield 'truncate' => [[-2.9], [O::Truncate], [-2.0]];
        yield 'and integers' => [[7, 3], [O::And], [3]];
        yield 'and booleans' => [[true, false], [O::And], [false]];
        yield 'shift left' => [[7, 3], [O::Bitshift], [56]];
        yield 'shift right' => [[142, -3], [O::Bitshift], [17]];
        yield 'logical right fills zero' => [[-1, -1], [O::Bitshift], [PHP_INT_MAX]];
        yield 'logical right word width' => [[-1, -64], [O::Bitshift], [0]];
        yield 'logical left word width' => [[-1, 64], [O::Bitshift], [0]];
        yield 'equal numeric types' => [[2, 2.0], [O::Equal], [true]];
        yield 'equal boolean and integer' => [[true, 1], [O::Equal], [false]];
        yield 'ge' => [[2, 2], [O::GreaterThanOrEqual], [true]];
        yield 'gt' => [[3, 2], [O::GreaterThan], [true]];
        yield 'le' => [[2, 2], [O::LessThanOrEqual], [true]];
        yield 'lt' => [[2, 3], [O::LessThan], [true]];
        yield 'ne' => [[2, 3], [O::NotEqual], [true]];
        yield 'not integer' => [[3], [O::Not], [-4]];
        yield 'not boolean' => [[false], [O::Not], [true]];
        yield 'or integers' => [[4, 3], [O::Or], [7]];
        yield 'or booleans' => [[true, false], [O::Or], [true]];
        yield 'xor integers' => [[7, 3], [O::Xor], [4]];
        yield 'xor booleans' => [[true, true], [O::Xor], [false]];
        yield 'copy' => [[1, 2, 2], [O::Copy], [1, 2, 1, 2]];
        yield 'dup' => [[3], [O::Duplicate], [3, 3]];
        yield 'exch' => [[2, 3], [O::Exchange], [3, 2]];
        yield 'index' => [[1, 2, 1], [O::Index], [1, 2, 1]];
        yield 'pop' => [[1, 2], [O::Pop], [1]];
        yield 'roll positive' => [[1, 2, 3, 3, 1], [O::Roll], [3, 1, 2]];
        yield 'roll negative' => [[1, 2, 3, 3, -1], [O::Roll], [2, 3, 1]];
        yield 'roll zero' => [[1, 0, PHP_INT_MIN], [O::Roll], [1]];
        yield 'copy zero' => [[1, 0], [O::Copy], [1]];
        yield 'integer addition promotes on overflow' => [[PHP_INT_MAX, 1], [O::Add], [(float) PHP_INT_MAX + 1]];
    }

    #[DataProvider('operations')]
    public function testOperatorSemanticsAndNumericKinds(array $operands, array $operators, array $expected): void
    {
        $instructions = [O::Pop];
        foreach ($operands as $operand) {
            $instructions[] = is_bool($operand) ? ($operand ? O::TrueValue : O::FalseValue) : $this->number($operand);
        }
        array_push($instructions, ...$operators);
        if (count($expected) === 1 && is_bool($expected[0])) {
            array_push($instructions, new Procedure($this->number(1)), new Procedure($this->number(0)), O::IfElse);
            $expected[0] = $expected[0] ? 1 : 0;
        }
        $function = $this->function(new Procedure(...$instructions), count($expected));
        $result = (new Evaluator())->evaluate($function, new NumericType(0));
        foreach ($expected as $index => $value) {
            $actual = $result->numbers[$index]->value;
            self::assertSame(get_debug_type($value), get_debug_type($actual));
            self::assertEqualsWithDelta($value, $actual, max(1.0, abs($value)) * 1.0e-12);
        }
    }

    public function testDomainAndRangeClippingAndInputOrder(): void
    {
        $domain = new Domain(new NumberInterval(new NumericType(0), new NumericType(1)), new NumberInterval(new NumericType(-2), new NumericType(2)));
        $range = new Range(new NumberInterval(new NumericType(-1), new NumericType(1)));
        $function = new PostScriptCalculatorFunction($domain, $range, new Procedure(O::Subtract));
        self::assertSame(1, (new Evaluator())->evaluate($function, new NumericType(10), new NumericType(-10))->numbers[0]->value);
        self::assertSame(-1, (new Evaluator())->evaluate($function, new NumericType(-10), new NumericType(10))->numbers[0]->value);
        $this->expectException(\InvalidArgumentException::class);
        (new Evaluator())->evaluate($function, new NumericType(0));
    }

    public function testDifferentBranchShapesAreDeferredAndNotMistakenForInvalidPrograms(): void
    {
        // Both branch shapes differ, but the later known-true conditional removes the extra value.
        $program = new Procedure(O::TrueValue, new Procedure($this->number(3)), new Procedure(), O::IfElse, O::TrueValue, new Procedure(O::Pop), O::IfThen);
        self::assertSame(7, (new Evaluator())->evaluate($this->function($program), new NumericType(7))->numbers[0]->value);
    }

    public function testConditionalBranchesConsumeOnlyTheChosenPathAndRequireBoolean(): void
    {
        $program = new Procedure(O::Duplicate, $this->number(0), O::GreaterThan, new Procedure($this->number(2), O::Multiply), new Procedure(O::Pop, O::Pop), O::IfElse);
        $function = $this->function($program);
        self::assertSame(6, (new Evaluator())->evaluate($function, new NumericType(3))->numbers[0]->value);
        $this->expectException(\UnderflowException::class);
        (new Evaluator())->evaluate($function, new NumericType(-3));
    }

    /** @return iterable<string,array{Procedure}> */
    public static function malformedPrograms(): iterable
    {
        yield 'underflow' => [new Procedure(O::Add)];
        yield 'extra output' => [new Procedure(O::Duplicate)];
        yield 'boolean output' => [new Procedure(O::Pop, O::TrueValue)];
        yield 'boolean numeric operand' => [new Procedure(O::TrueValue, O::Add)];
        yield 'standalone procedure' => [new Procedure(new Procedure(), O::Pop)];
        yield 'missing body' => [new Procedure(O::IfThen)];
        yield 'two bodies need ifelse' => [new Procedure(new Procedure(), new Procedure(), O::IfThen)];
        yield 'real passed to idiv' => [new Procedure(new Number(new RealType(2)), O::IntegerDivide)];
    }

    #[DataProvider('malformedPrograms')]
    public function testProvenInvalidGrammarAndStraightLineContractsCannotBeEmitted(Procedure $procedure): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->function($procedure);
    }

    public function testBraceNestingCeilingIsEnforcedAtExactly255(): void
    {
        $body = new Procedure();
        for ($depth = 1; $depth < 255; ++$depth) {
            $body = new Procedure(O::TrueValue, $body, O::IfThen);
        }
        self::assertSame(2, (new Evaluator())->evaluate($this->function($body), new NumericType(2))->numbers[0]->value);
        $this->expectException(\LengthException::class);
        $this->function(new Procedure(O::TrueValue, $body, O::IfThen));
    }

    public function testDecimalLiteralLeadingZerosPreserveIntegerKindAndOriginalToken(): void
    {
        foreach (['0001' => 1, '-01' => -1, '+00012' => 12, '-000' => 0, '+0' => 0, '009223372036854775807' => PHP_INT_MAX, '-009223372036854775808' => PHP_INT_MIN] as $token => $expected) {
            $literal = new \PDF\Functions\Calculator\Literal((string) $token);
            self::assertSame($expected, $literal->value);
            self::assertSame((string) $token, $literal->toPostScript());
        }
        foreach (['009223372036854775808', '-009223372036854775809', '+0001.0'] as $token) {
            $literal = new \PDF\Functions\Calculator\Literal($token);
            self::assertIsFloat($literal->value);
            self::assertSame($token, $literal->toPostScript());
        }
    }

    public function testEvaluationUsesTheFrozenEmittedNumericToken(): void
    {
        $number = new class (1) extends NumericType {
            public string $token = '1';
            public function toPdf(SerializationContext $context): string
            {
                return $this->token;
            }
        };
        $function = $this->function(new Procedure(O::Pop, new Number($number)));
        $number->token = '99';
        self::assertSame('{ pop 1 }', $function->program->toPostScript());
        self::assertSame(1, (new Evaluator())->evaluate($function, new NumericType(0))->numbers[0]->value);
    }

    public function testWorkAndStackBudgetsArePerInvocation(): void
    {
        $instructions = array_fill(0, 100, $this->number(1));
        array_push($instructions, ...array_fill(0, 100, O::Pop));
        $function = $this->function(new Procedure(...$instructions));
        $evaluator = new Evaluator(new CalculatorLimits(maxStack: 101));
        self::assertSame(3, $evaluator->evaluate($function, new NumericType(3))->numbers[0]->value);
        self::assertSame(4, $evaluator->evaluate($function, new NumericType(4))->numbers[0]->value);
        $this->expectException(\LengthException::class);
        (new Evaluator())->evaluate($function, new NumericType(3));
    }

    public function testOperationBudgetCannotBeBypassedThroughNestedConditionals(): void
    {
        $function = $this->function(new Procedure(O::TrueValue, new Procedure(O::Duplicate, O::Pop), O::IfThen));
        $this->expectException(\LengthException::class);
        (new Evaluator(new CalculatorLimits(maxOperations: 3)))->evaluate($function, new NumericType(0));
    }

    public function testCompilerHasAnExplicitSourceBudget(): void
    {
        $this->expectException(\LengthException::class);
        (new ProgramCompiler(new CalculatorLimits(maxInstructions: 1)))->compile(new Procedure(O::Duplicate, O::Pop));
    }

    /** @return iterable<string,array{Procedure,class-string<\Throwable>}> */
    public static function executionFailures(): iterable
    {
        $n = static fn (int|float $v): Number => new Number(is_float($v) ? new RealType($v) : new NumericType($v));
        yield 'zero divisor' => [new Procedure($n(0), O::Divide), \DomainException::class];
        yield 'negative square root' => [new Procedure(O::Pop, $n(-1), O::SquareRoot), \DomainException::class];
        yield 'zero log' => [new Procedure(O::Pop, $n(0), O::Logarithm), \DomainException::class];
        yield 'negative ln' => [new Procedure(O::Pop, $n(-1), O::NaturalLogarithm), \DomainException::class];
        yield 'undefined atan' => [new Procedure(O::Pop, $n(0), $n(0), O::Atan), \DomainException::class];
        yield 'overflow cvi' => [new Procedure(O::Pop, $n(1.0e30), O::ConvertToInteger), \DomainException::class];
        yield 'nonfinite exp' => [new Procedure(O::Pop, $n(10), $n(1000), O::Exponentiate), \DomainException::class];
        yield 'negative fractional exp' => [new Procedure(O::Pop, $n(-1), $n(0.5), O::Exponentiate), \DomainException::class];
        yield 'integer overflow idiv' => [new Procedure(O::Pop, $n(PHP_INT_MIN), $n(-1), O::IntegerDivide), \DomainException::class];
        yield 'copy outside stack' => [new Procedure($n(3), O::Copy), \OutOfRangeException::class];
        yield 'negative index' => [new Procedure($n(-1), O::Index), \OutOfRangeException::class];
        yield 'roll outside stack' => [new Procedure($n(3), $n(1), O::Roll), \OutOfRangeException::class];
        yield 'nonboolean condition' => [new Procedure(new Procedure(), O::IfThen), \UnexpectedValueException::class];
        yield 'conditional leaves boolean' => [new Procedure(O::TrueValue, new Procedure(O::Pop, O::FalseValue), O::IfThen), \UnexpectedValueException::class];
        yield 'conditional wrong output count' => [new Procedure(O::TrueValue, new Procedure(O::Duplicate), O::IfThen), \UnexpectedValueException::class];
    }

    #[DataProvider('executionFailures')]
    public function testRuntimeFailuresRemainExplicit(Procedure $procedure, string $exception): void
    {
        $function = $this->function($procedure);
        $this->expectException($exception);
        (new Evaluator())->evaluate($function, new NumericType(1));
    }

    private function number(int|float $number): Number
    {
        return new Number(is_float($number) ? new RealType($number) : new NumericType($number));
    }

    private function function(Procedure $procedure, int $outputs = 1): PostScriptCalculatorFunction
    {
        $interval = new NumberInterval(new NumericType(-1.0e100), new NumericType(1.0e100));
        return new PostScriptCalculatorFunction(new Domain($interval), new Range(...array_fill(0, $outputs, $interval)), $procedure);
    }
}
