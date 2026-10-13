<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\IntegerType;

/** Table 123 flags; Symbolic and Nonsymbolic must be mutually exclusive. */
final class FontDescriptorFlags extends IntegerType
{
    public function __construct(int $value)
    {
        if ($value < 0 || ($value & ~0x7006F) !== 0 || !in_array($value & 36, [4, 32], true)) {
            throw new \InvalidArgumentException('Invalid font descriptor flags; select Symbolic or Nonsymbolic.');
        }
        parent::__construct($value);
    }
}
