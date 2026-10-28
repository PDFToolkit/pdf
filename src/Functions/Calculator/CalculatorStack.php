<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** Per-evaluation bounded operand stack; it cannot retain procedures or external objects. */
final class CalculatorStack
{
    /** @var list<int|float|bool> */
    private array $values = [];

    public function __construct(private readonly CalculatorLimits $limits)
    {
    }

    public function push(int|float|bool $value): void
    {
        if (count($this->values) >= $this->limits->maxStack) {
            throw new \LengthException('Calculator operand stack overflow.');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \DomainException('Calculator arithmetic produced a non-finite result.');
        }
        $this->values[] = $value;
    }

    public function pop(): int|float|bool
    {
        if ($this->values === []) {
            throw new \UnderflowException('Calculator operand stack underflow.');
        }
        return array_pop($this->values);
    }

    public function index(int $index): int|float|bool
    {
        if ($index < 0 || $index >= count($this->values)) {
            throw new \OutOfRangeException('Calculator index lies outside the operand stack.');
        }
        return $this->values[count($this->values) - 1 - $index];
    }

    public function copy(int $count): void
    {
        if ($count < 0 || $count > count($this->values)) {
            throw new \OutOfRangeException('Calculator copy count lies outside the operand stack.');
        }
        if ($count > $this->limits->maxStack - count($this->values)) {
            throw new \LengthException('Calculator operand stack overflow.');
        }
        if ($count > 0) {
            array_push($this->values, ...array_slice($this->values, -$count));
        }
    }

    public function roll(int $count, int $amount): void
    {
        if ($count < 0 || $count > count($this->values)) {
            throw new \OutOfRangeException('Calculator roll count lies outside the operand stack.');
        }
        if ($count === 0) {
            return;
        }
        $amount %= $count;
        if ($amount < 0) {
            $amount += $count;
        }
        if ($amount > 0) {
            $tail = array_splice($this->values, -$count);
            array_push($this->values, ...array_slice($tail, -$amount), ...array_slice($tail, 0, $count - $amount));
        }
    }

    /** @return list<int|float|bool> */
    public function values(): array
    {
        return $this->values;
    }
}
