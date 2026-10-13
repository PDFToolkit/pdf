<?php

declare(strict_types=1);

namespace PDF\Security;

/** Frozen, reproducible Unicode 3.2 data required by RFC 3454 and RFC 4013. */
final readonly class Unicode32SaslprepTables
{
    /** @var array<int, list<int>> */
    public array $decomposition;
    /** @var array<int, int> */
    public array $combining;
    /** @var array<string, int> */
    public array $composition;
    /** @var array<string, list<array{int, int}>> */
    private array $ranges;

    public function __construct(Unicode32SaslprepData $data = new Unicode32SaslprepData())
    {
        $this->decomposition = $data->decomposition;
        $this->combining = $data->combining;
        $this->composition = $data->composition;
        $this->ranges = $data->ranges;
    }

    public function contains(string $table, int $point): bool
    {
        $ranges = $this->ranges[$table] ?? throw new \InvalidArgumentException('Unknown SASLprep character table.');
        $low = 0;
        $high = count($ranges) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            [$first, $last] = $ranges[$middle];
            if ($point < $first) {
                $high = $middle - 1;
            } elseif ($point > $last) {
                $low = $middle + 1;
            } else {
                return true;
            }
        }
        return false;
    }
}
