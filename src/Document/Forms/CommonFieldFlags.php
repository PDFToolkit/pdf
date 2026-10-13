<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

final readonly class CommonFieldFlags extends FieldFlags
{
    public function __construct(CommonFieldFlag ...$flags)
    {
        parent::__construct(...$flags);
    }
}
