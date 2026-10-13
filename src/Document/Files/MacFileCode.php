<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

/** Four ASCII characters interpreted as a big-endian32-bit integer, Table47/page113. */
final readonly class MacFileCode
{
    public IntegerType $number;

    public function __construct(public StringType $characters)
    {
        if (strlen($characters->value) !== 4 || preg_match('/[^\x00-\x7F]/', $characters->value) === 1) {
            throw new \InvalidArgumentException('A Mac file type or creator code requires exactly four ASCII bytes.');
        }
        $this->number = new IntegerType((ord($characters->value[0]) << 24) | (ord($characters->value[1]) << 16) | (ord($characters->value[2]) << 8) | ord($characters->value[3]));
    }
}
