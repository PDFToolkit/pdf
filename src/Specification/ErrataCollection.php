<?php

declare(strict_types=1);

namespace PDF\Specification;

/** An explicitly selected correction collection does not change the base standard edition. */
final readonly class ErrataCollection
{
    public function __construct(public int $number, public string $publicationDate)
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $publicationDate);
        if ($number < 1 || $date === false || $date->format('Y-m-d') !== $publicationDate) {
            throw new \InvalidArgumentException('An errata collection requires a positive number and a valid ISO date.');
        }
    }
}
