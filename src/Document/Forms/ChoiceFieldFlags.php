<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

final readonly class ChoiceFieldFlags extends FieldFlags
{
    public function __construct(CommonFieldFlag|ChoiceFieldFlag ...$flags)
    {
        parent::__construct(...$flags);
    }
}
