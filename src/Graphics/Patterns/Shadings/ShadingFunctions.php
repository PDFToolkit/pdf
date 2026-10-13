<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\Domain;
use PDF\Functions\FunctionObject;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;

/** One n-output function or an ordered array of scalar-output functions (tables 79–84). */
final readonly class ShadingFunctions
{
    /** @var non-empty-list<FunctionObject> */
    public array $functions;

    public function __construct(FunctionObject ...$functions)
    {
        if ($functions === []) {
            throw new \InvalidArgumentException('A Function entry needs at least one explicit function.');
        }
        $this->functions = array_values($functions);
    }

    public function validate(int $inputs, int $outputs, Domain $domain): void
    {
        if ($domain->dimensions() !== $inputs || (count($this->functions) !== 1 && count($this->functions) !== $outputs)) {
            throw new \InvalidArgumentException('Shading functions must match all input coordinates and output colour components.');
        }
        foreach ($this->functions as $function) {
            if ($function->inputCount() !== $inputs || $function->outputCount() !== (count($this->functions) === 1 ? $outputs : 1)) {
                throw new \InvalidArgumentException('A shading function has incompatible input/output dimensions.');
            }
            foreach ($domain->intervals as $index => $interval) {
                $functionInterval = $function->domain()->intervals[$index];
                if ($functionInterval->minimum->value > $interval->minimum->value || $functionInterval->maximum->value < $interval->maximum->value) {
                    throw new \InvalidArgumentException('Every shading function domain must cover the shading domain completely.');
                }
            }
        }
    }

    public function value(): BasicType
    {
        $references = array_map(static fn (FunctionObject $function): IndirectReference => new IndirectReference($function), $this->functions);
        return count($references) === 1 ? $references[0] : new ArrayType(...$references);
    }
}
