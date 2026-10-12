<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\IndirectObject;

final readonly class ObjectNumber implements \Stringable
{
    public function __construct(public int $value = 1)
    {
        if ($value < 1 || $value > PHP_INT_MAX) {
            throw new \InvalidArgumentException('Invalid ObjectNumber.');
        }
    }
    public function number(): int
    {
        return $this->value;
    }
    public function increment(): self
    {
        if ($this->value === PHP_INT_MAX) {
            throw new \OverflowException('ObjectNumber overflow.');
        }
        return new self($this->value + 1);
    }
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
