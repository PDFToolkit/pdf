<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

final readonly class TextFieldFlags extends FieldFlags
{
    public function __construct(CommonFieldFlag|TextFieldFlag ...$flags)
    {
        parent::__construct(...$flags);
    }
}
