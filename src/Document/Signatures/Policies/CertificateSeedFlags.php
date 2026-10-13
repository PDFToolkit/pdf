<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\Object\IntegerType;

final class CertificateSeedFlags extends IntegerType
{
    public function __construct(CertificateSeedFlag ...$flags)
    {
        $value = 0;
        foreach ($flags as $flag) {
            $value |= $flag->value;
        }
        parent::__construct($value);
    }
    public function requires(CertificateSeedFlag $flag): bool
    {
        return ($this->integer() & $flag->value) !== 0;
    }
}
