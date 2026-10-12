<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Functions\Calculator\Procedure;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** FunctionType 4 stream containing a typed, braced calculator program. */
final class PostScriptCalculatorFunction extends StreamObject implements FunctionObject
{
    public function __construct(private readonly Domain $inputDomain, private readonly Range $outputRange, public readonly Procedure $procedure)
    {
        parent::__construct($procedure->toPostScript(), new DictionaryType(
            new Entry(new NameType('FunctionType'), new IntegerType(4)),
            new Entry(new NameType('Domain'), $inputDomain),
            new Entry(new NameType('Range'), $outputRange),
        ));
    }

    public function domain(): Domain
    {
        return $this->inputDomain;
    }

    public function range(): Range
    {
        return $this->outputRange;
    }

    public function inputCount(): int
    {
        return $this->inputDomain->dimensions();
    }

    public function outputCount(): int
    {
        return $this->outputRange->dimensions();
    }
}
