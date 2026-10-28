<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** Frozen calculator program used for both serialization and explicit evaluation. */
final readonly class Program implements Instruction
{
    /** @var list<Literal|Operator|Conditional> */
    public array $instructions;

    public function __construct(Literal|Operator|Conditional ...$instructions)
    {
        foreach ($instructions as $instruction) {
            if ($instruction === Operator::IfThen || $instruction === Operator::IfElse) {
                throw new \InvalidArgumentException('Compiled conditionals require explicit lexical bodies.');
            }
        }
        $this->instructions = array_values($instructions);
    }

    public function toPostScript(): string
    {
        return '{ ' . implode(' ', array_map(static fn (Instruction $instruction): string => $instruction->toPostScript(), $this->instructions)) . ' }';
    }
}
