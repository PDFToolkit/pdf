<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** One common transfer function or four process-component functions (10.4). */
final readonly class TransferFunctions
{
    /** @var non-empty-list<ScalarColourFunction|ColourFunctionName> */
    public array $functions;

    public function __construct(ScalarColourFunction|ColourFunctionName ...$functions)
    {
        if (!in_array(count($functions), [1, 4], true)) {
            throw new \InvalidArgumentException('Transfer functions require one common selection or four process-component functions.');
        }
        if (count($functions) === 4) {
            foreach ($functions as $function) {
                if (!$function instanceof ScalarColourFunction) {
                    throw new \InvalidArgumentException('Four-component transfer arrays contain function objects; named Identity/Default are common selections.');
                }
            }
        }
        $this->functions = array_values($functions);
    }

    public function value(): IndirectReference|NameType|ArrayType
    {
        $values = array_map(static fn (ScalarColourFunction|ColourFunctionName $function): IndirectReference|NameType => $function instanceof ScalarColourFunction ? $function->value() : new NameType($function->value), $this->functions);
        return count($values) === 1 ? $values[0] : new ArrayType(...$values);
    }
}
