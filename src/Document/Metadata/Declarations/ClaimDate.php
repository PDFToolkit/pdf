<?php

declare(strict_types=1);

namespace PDF\Document\Metadata\Declarations;

/** An explicit XMP date (ISO 8601), retaining the author's precision and time zone. */
final readonly class ClaimDate
{
    public function __construct(public string $value)
    {
        if (preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?(Z|[+-]\d{2}:\d{2}))?)?)?$/D', $value, $m) !== 1
            || (int) $m[1] === 0 || (isset($m[2]) && ((int) $m[2] < 1 || (int) $m[2] > 12))
            || (isset($m[3]) && !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))
            || (isset($m[4]) && ((int) $m[4] > 23 || (int) ($m[5] ?? '') > 59 || (isset($m[6]) && $m[6] !== '' && (int) $m[6] > 59)))
            || (isset($m[8]) && $m[8] !== 'Z' && ((int) substr($m[8], 1, 2) > 23 || (int) substr($m[8], 4, 2) > 59))) {
            throw new \InvalidArgumentException('A claim date requires a real ISO 8601 date with an explicit zone for a time.');
        }
    }
}
