<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\IntegerType;

/** Ordered CID interval with the PDF implementation bound 65535. */
final readonly class CIDRange
{
    public function __construct(public IntegerType $first, public IntegerType $last)
    {
        if ($first->integer() < 0 || $last->integer() > 65535 || $first->integer() > $last->integer()) {
            throw new \InvalidArgumentException('CID ranges are ascending and contained in 0–65535.');
        }
    }
    public function contains(int $cid): bool
    {
        return $cid >= $this->first->integer() && $cid <= $this->last->integer();
    }
    public function overlaps(self $other): bool
    {
        return $this->first->integer() <= $other->last->integer() && $other->first->integer() <= $this->last->integer();
    }
}
