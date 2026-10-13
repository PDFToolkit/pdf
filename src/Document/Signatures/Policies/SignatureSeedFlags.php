<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\Object\IntegerType;

final class SignatureSeedFlags extends IntegerType
{
    public function __construct(SignatureSeedFlag ...$flags)
    {
        $value = 0;
        foreach ($flags as $flag) {
            $value |= $flag->value;
        }
        parent::__construct($value);
    }
    public function requires(SignatureSeedFlag $flag): bool
    {
        return ($this->integer() & $flag->value) !== 0;
    }
}
