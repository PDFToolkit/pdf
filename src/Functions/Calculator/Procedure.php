<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** Braced calculator instructions; nested procedures are explicit objects. */
final readonly class Procedure implements Instruction
{
    /** @var list<Instruction> */
    public array $instructions;

    public function __construct(Instruction ...$instructions)
    {
        $this->instructions = array_values($instructions);
    }

    public function toPostScript(): string
    {
        return '{ ' . implode(' ', array_map(static fn (Instruction $instruction): string => $instruction->toPostScript(), $this->instructions)) . ' }';
    }
}
