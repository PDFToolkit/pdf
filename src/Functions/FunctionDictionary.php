<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Common required entries for dictionary-valued PDF functions. */
abstract class FunctionDictionary extends DictionaryType implements FunctionObject
{
    protected function __construct(IntegerType $type, private readonly Domain $inputDomain, private readonly ?Range $outputRange, Entry ...$entries)
    {
        $required = [new Entry(new NameType('FunctionType'), $type), new Entry(new NameType('Domain'), $inputDomain)];
        if ($outputRange !== null) {
            $required[] = new Entry(new NameType('Range'), $outputRange);
        }
        parent::__construct(...$required, ...$entries);
    }

    public function domain(): Domain
    {
        return $this->inputDomain;
    }

    public function range(): ?Range
    {
        return $this->outputRange;
    }

    public function inputCount(): int
    {
        return $this->inputDomain->dimensions();
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Function entries are validated together in their constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Function entries cannot be removed independently of their function model.');
    }
}
