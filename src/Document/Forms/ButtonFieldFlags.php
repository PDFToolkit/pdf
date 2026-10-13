<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

final readonly class ButtonFieldFlags extends FieldFlags
{
    public function __construct(CommonFieldFlag|ButtonFieldFlag ...$flags)
    {
        parent::__construct(...$flags);
    }
}
