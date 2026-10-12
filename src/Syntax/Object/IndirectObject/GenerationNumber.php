<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\IndirectObject;

final readonly class GenerationNumber implements \Stringable
{
    public function __construct(public int $value = 0)
    {
        if ($value < 0 || $value > 65535) {
            throw new \InvalidArgumentException('Invalid GenerationNumber.');
        }
    }
    public function number(): int
    {
        return $this->value;
    }
    public function increment(): self
    {
        if ($this->value === 65535) {
            throw new \OverflowException('GenerationNumber overflow.');
        }
        return new self($this->value + 1);
    }
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
