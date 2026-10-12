<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** The complete permitted calculator operator subset from table 42. */
enum Operator: string implements Instruction
{
    case Abs = 'abs';
    case Add = 'add';
    case Atan = 'atan';
    case Ceiling = 'ceiling';
    case Cos = 'cos';
    case ConvertToInteger = 'cvi';
    case ConvertToReal = 'cvr';
    case Divide = 'div';
    case Exponentiate = 'exp';
    case Floor = 'floor';
    case IntegerDivide = 'idiv';
    case NaturalLogarithm = 'ln';
    case Logarithm = 'log';
    case Modulo = 'mod';
    case Multiply = 'mul';
    case Negate = 'neg';
    case Round = 'round';
    case Sin = 'sin';
    case SquareRoot = 'sqrt';
    case Subtract = 'sub';
    case Truncate = 'truncate';
    case And = 'and';
    case Bitshift = 'bitshift';
    case Equal = 'eq';
    case FalseValue = 'false';
    case GreaterThanOrEqual = 'ge';
    case GreaterThan = 'gt';
    case LessThanOrEqual = 'le';
    case LessThan = 'lt';
    case NotEqual = 'ne';
    case Not = 'not';
    case Or = 'or';
    case TrueValue = 'true';
    case Xor = 'xor';
    case IfThen = 'if';
    case IfElse = 'ifelse';
    case Copy = 'copy';
    case Duplicate = 'dup';
    case Exchange = 'exch';
    case Index = 'index';
    case Pop = 'pop';
    case Roll = 'roll';

    public function toPostScript(): string
    {
        return $this->value;
    }
}
