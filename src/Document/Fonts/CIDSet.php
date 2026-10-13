<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\IntegerType;

/** Table 124: CID subset presence bits, most significant bit first, including CID 0. */
final class CIDSet extends FontStream
{
    /** @var list<int> */
    public readonly array $cids;

    /** @param list<IntegerType> $cids */
    public function __construct(array $cids, Filter ...$filters)
    {
        $numbers = [];
        foreach ($cids as $cid) {
            if ($cid->integer() < 0 || $cid->integer() > 65535 || isset($numbers[$cid->integer()])) {
                throw new \InvalidArgumentException('CIDSet has distinct CIDs in 0–65535.');
            }
            $numbers[$cid->integer()] = $cid->integer();
        }
        if (!isset($numbers[0])) {
            throw new \InvalidArgumentException('Every CIDFont subset contains CID 0 (.notdef).');
        }
        ksort($numbers);
        $this->cids = array_values($numbers);
        $bytes = str_repeat("\0", intdiv(max($numbers), 8) + 1);
        foreach ($numbers as $cid) {
            $offset = intdiv($cid, 8);
            $bytes[$offset] = chr(ord($bytes[$offset]) | (128 >> ($cid % 8)));
        }
        parent::__construct($bytes, null, ...$filters);
    }

    public function contains(int $cid): bool
    {
        return in_array($cid, $this->cids, true);
    }
}
