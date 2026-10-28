<?php

declare(strict_types=1);

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Functions\Calculator\Evaluator;
use PDF\Functions\Calculator\Number;
use PDF\Functions\Calculator\Operator as O;
use PDF\Functions\Calculator\Procedure;
use PDF\Functions\Domain;
use PDF\Functions\NumberInterval;
use PDF\Functions\PostScriptCalculatorFunction;
use PDF\Functions\Range;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\RealType;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final readonly class CalculatorFunctionFixtures
{
    public function emit(): void
    {
        $cases = [];
        foreach (O::cases() as $operator) {
            $operands = match ($operator) {
                O::Abs, O::Negate => [-7],
                O::Ceiling, O::Floor, O::Round, O::Truncate, O::ConvertToInteger => [-2.5],
                O::ConvertToReal => [2],
                O::SquareRoot => [4],
                O::Sin, O::Cos => [30],
                O::NaturalLogarithm, O::Logarithm => [10],
                O::Not => [3],
                O::Copy => [1, 2, 2],
                O::Index => [1, 2, 1],
                O::Roll => [1, 2, 3, 3, -1],
                O::Duplicate => [3],
                O::TrueValue, O::FalseValue, O::IfThen, O::IfElse => [],
                default => [7, 3],
            };
            $instructions = [O::Pop];
            foreach ($operands as $operand) {
                $instructions[] = $this->number($operand);
            }
            if ($operator === O::IfThen || $operator === O::IfElse) {
                array_push($instructions, O::TrueValue, new Procedure($this->number(7)));
                if ($operator === O::IfElse) {
                    $instructions[] = new Procedure($this->number(3));
                }
            }
            $instructions[] = $operator;
            if (in_array($operator, [O::TrueValue, O::FalseValue, O::Equal, O::NotEqual, O::GreaterThan, O::GreaterThanOrEqual, O::LessThan, O::LessThanOrEqual], true)) {
                array_push($instructions, new Procedure($this->number(1)), new Procedure($this->number(0)), O::IfElse);
            }
            $outputs = match ($operator) {
                O::Copy => 4,
                O::Index, O::Roll => 3,
                O::Duplicate, O::Exchange => 2,
                default => 1,
            };
            $cases[] = $this->sample($operator->value, new Procedure(...$instructions), $outputs, [0]);
        }
        $cases[] = $this->sample('domain-range-clipping', new Procedure(O::Duplicate, O::Multiply), 1, [-2, 0.5, 3], true);
        $cases[] = $this->sample('conditional-pdf-example', new Procedure(O::Duplicate, $this->number(0.5), O::GreaterThan, new Procedure($this->number(1), O::Exchange, O::Subtract), new Procedure(O::Duplicate, O::Multiply), O::IfElse), 1, [0, 0.25, 0.5, 0.75, 1], true);
        echo json_encode(['cases' => $cases], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), "\n";
    }

    /** @param list<int|float> $inputs @return array<string,mixed> */
    private function sample(string $name, Procedure $program, int $outputs, array $inputs, bool $clip = false): array
    {
        $interval = new NumberInterval(new NumericType($clip ? 0 : -100000), new NumericType($clip ? 1 : 100000));
        $function = new PostScriptCalculatorFunction(new Domain($interval), new Range(...array_fill(0, $outputs, $interval)), $program);
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary());
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('ExampleCalculator'), new IndirectReference($function)));
        $bytes = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf();
        $evaluations = [];
        foreach ($inputs as $input) {
            $result = (new Evaluator())->evaluate($function, new NumericType($input));
            $evaluations[] = ['inputs' => [$input], 'outputs' => array_map(static fn (NumericType $number): int|float => $number->value, $result->numbers)];
        }
        return ['name' => $name, 'pdf' => base64_encode($bytes), 'evaluations' => $evaluations];
    }

    private function number(int|float $value): Number
    {
        return new Number(is_float($value) ? new RealType($value) : new NumericType($value));
    }
}

(new CalculatorFunctionFixtures())->emit();
