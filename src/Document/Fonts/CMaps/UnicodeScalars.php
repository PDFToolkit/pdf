<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\StringObject\HexadecimalString;

final readonly class UnicodeScalars extends UnicodeSequence
{
    public function __construct(UnicodeScalar ...$scalars)
    {
        parent::__construct(new HexadecimalString(implode('', array_map(static fn (UnicodeScalar $scalar) => $scalar->sequence()->value->value, $scalars))));
    }
}
