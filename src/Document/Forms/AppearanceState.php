<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\Object\NameType;

final readonly class AppearanceState
{
    public function __construct(public NameType $name, public FormAppearanceStream $stream)
    {
    }
}
